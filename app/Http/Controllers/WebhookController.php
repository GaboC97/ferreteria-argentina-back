<?php

namespace App\Http\Controllers;

use App\Mail\PedidoConfirmadoAdmin;
use App\Mail\PedidoConfirmadoCliente;
use App\Mail\ContenedorReservaConfirmadaAdmin;
use App\Mail\ContenedorReservaConfirmadaCliente;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\ReservaStock;
use App\Models\StockSucursal;
use App\Models\ContenedorReserva;
use App\Models\Producto;
use App\Models\Webhook;
use App\Services\ContenedorReservaService;
use App\Services\PaljetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
public function mercadoPago(Request $request)
{
    // ==========================
    // 0) Datos básicos del evento
    // ==========================
    $topic = $request->query('topic') ?? $request->input('type') ?? $request->query('type');
    $event = $request->query('type') ?? $request->input('action') ?? $request->query('action');

    $externalId =
        $request->query('id')
        ?? data_get($request->all(), 'data.id')
        ?? $request->input('id');

    $rawBody = $request->getContent() ?: '';

    // ==========================
    // 0.1) Guardar SIEMPRE el webhook
    // (evita error 1364 payload_json NOT NULL)
    // ==========================
    try {
        $webhook = Webhook::create([
            'proveedor'    => 'mercadopago',
            'evento'       => (string)($topic ?: $event ?: 'unknown'),
            'external_id'  => $externalId ? (string)$externalId : null,
            'payload_json' => $rawBody, // ✅ CLAVE
            'procesado'    => 0,
            'procesado_en' => null,
        ]);
    } catch (\Throwable $e) {
        // Si por alguna razón falla el INSERT, no cortamos el webhook
        Log::error('MP Webhook: no se pudo guardar en tabla webhooks', [
            'error' => $e->getMessage(),
        ]);

        // Creamos un "stub" para poder seguir (evita null->id en logs)
        $webhook = new \stdClass();
        $webhook->id = null;
        $webhook->procesado = 0;
    }

    // ==========================
    // 1) Validación de firma (tolerante)
    // ==========================
    $secret     = config('services.mercadopago.webhook_secret');
    $xSignature = $request->header('x-signature');
    $xRequestId = $request->header('x-request-id');

    $firmaOk = false;
    $firmaIntentada = false;

    // Solo intentamos validar si tenemos todo
    if (!empty($secret) && !empty($xSignature) && !empty($xRequestId) && !empty($externalId)) {
        $firmaIntentada = true;

        // x-signature: "ts=...,v1=..."
        $ts = null;
        $v1 = null;

        foreach (explode(',', $xSignature) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'ts=')) $ts = substr($part, 3);
            if (str_starts_with($part, 'v1=')) $v1 = substr($part, 3);
        }

        if (!empty($ts) && !empty($v1)) {
            // ✅ MANIFEST (formato correcto para MP Webhooks)
            $manifest = "id:{$externalId};request-id:{$xRequestId};ts:{$ts};";
            $computed = hash_hmac('sha256', $manifest, $secret);

            $firmaOk = hash_equals(strtolower($computed), strtolower($v1));

            if (!$firmaOk) {
                Log::warning('MP Webhook: firma inválida (modo tolerante, seguimos igual)', [
                    'webhook_id'   => $webhook->id ?? null,
                    'manifest'     => $manifest,
                    'computed'     => $computed,
                    'received'     => $v1,
                    'x_request_id' => $xRequestId,
                    'x_signature'  => $xSignature,
                ]);

                // 🔒 Si querés MODO ESTRICTO, descomentá:
                // return response()->json(['error' => 'Invalid signature'], 400);
            }
        } else {
            Log::warning('MP Webhook: formato x-signature inválido', [
                'webhook_id'  => $webhook->id ?? null,
                'x_signature' => $xSignature,
            ]);
        }
    } else {
        // No cortamos: a veces faltan headers en variaciones/IPN
        if (empty($secret)) {
            Log::warning('MP Webhook: MP_WEBHOOK_SECRET no configurado (modo tolerante)', [
                'webhook_id' => $webhook->id ?? null,
            ]);
        }
    }

    // ==========================
    // 2) Procesamiento (siempre consultando a MP)
    // ==========================
    try {
        $mpAccessToken = config('services.mercadopago.access_token');
        if (!$mpAccessToken) {
            throw new \Exception('MP_ACCESS_TOKEN no configurado');
        }

        // Normalizar tipo:
        // - Webhook nuevo: body trae type=payment y data.id
        // - IPN viejo: query trae topic=payment e id
        $isPayment = ($topic === 'payment') || ($request->input('type') === 'payment');
        $isMerchantOrder = ($topic === 'merchant_order');

        if ($isPayment) {
            $paymentId = $externalId ?: data_get($request->all(), 'data.id');

            if (!$paymentId) {
                if ($webhook->id) {
                    Webhook::where('id', $webhook->id)->update([
                        'procesado' => 1,
                        'procesado_en' => now(),
                    ]);
                }
                return response()->json(['ok' => true, 'note' => 'no payment id'], 200);
            }

            $payment = $this->mpGetPayment((string)$paymentId, $mpAccessToken);
            return $this->processPayment($payment, $webhook);
        }

        if ($isMerchantOrder) {
            $merchantOrderId = $externalId ?: data_get($request->all(), 'data.id');

            if (!$merchantOrderId) {
                if ($webhook->id) {
                    Webhook::where('id', $webhook->id)->update([
                        'procesado' => 1,
                        'procesado_en' => now(),
                    ]);
                }
                return response()->json(['ok' => true, 'note' => 'no merchant_order id'], 200);
            }

            $merchantOrder = $this->mpGetMerchantOrder((string)$merchantOrderId, $mpAccessToken);

            $payments = data_get($merchantOrder, 'payments', []);
            if (!is_array($payments) || count($payments) === 0) {
                if ($webhook->id) {
                    Webhook::where('id', $webhook->id)->update([
                        'procesado' => 1,
                        'procesado_en' => now(),
                    ]);
                }
                return response()->json(['ok' => true, 'note' => 'no payments in merchant_order'], 200);
            }

            foreach ($payments as $p) {
                $pid = data_get($p, 'id');
                if ($pid) {
                    $payment = $this->mpGetPayment((string)$pid, $mpAccessToken);
                    $this->processPayment($payment, $webhook, true);
                }
            }

            if ($webhook->id) {
                Webhook::where('id', $webhook->id)->update([
                    'procesado' => 1,
                    'procesado_en' => now(),
                ]);
            }

            return response()->json(['ok' => true], 200);
        }

        if ($webhook->id) {
            Webhook::where('id', $webhook->id)->update([
                'procesado' => 1,
                'procesado_en' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'note' => 'topic not handled'], 200);

    } catch (\Throwable $e) {

        Log::error('MP WEBHOOK ERROR', [
            'error'          => $e->getMessage(),
            'trace'          => $e->getTraceAsString(),
            'webhook_id'     => $webhook->id ?? null,
            'firma_intentada'=> $firmaIntentada,
            'firma_ok'       => $firmaOk,
        ]);

        // ✅ Devolvemos 200 para que MP no reintente infinito.
        // Si querés que reintente (ej: caído momentáneo), devolvé 500.
        return response()->json(['ok' => false], 200);
    }
}

    private function mpGetPayment(string $paymentId, string $token): array
    {
        $resp = Http::withToken($token)
            ->acceptJson()
            ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

        if (!$resp->successful()) {
            throw new \Exception("Error MP get payment {$paymentId}: " . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

    private function mpGetMerchantOrder(string $merchantOrderId, string $token): array
    {
        $resp = Http::withToken($token)
            ->acceptJson()
            ->get("https://api.mercadopago.com/merchant_orders/{$merchantOrderId}");

        if (!$resp->successful()) {
            throw new \Exception("Error MP get merchant_order {$merchantOrderId}: " . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

private function processPayment(array $payment, Webhook $webhook, bool $silent = false)
{
    $paymentId       = (string) data_get($payment, 'id');
    $status          = (string) data_get($payment, 'status');
    $statusDetail    = (string) data_get($payment, 'status_detail');
    $merchantOrderId = data_get($payment, 'order.id') ? (string) data_get($payment, 'order.id') : null;

    $pagoIdRaw   = data_get($payment, 'metadata.pago_id');
    $pedidoIdRaw = data_get($payment, 'metadata.pedido_id') ?: data_get($payment, 'external_reference');

    $pedidoId = is_numeric($pedidoIdRaw) ? (int) $pedidoIdRaw : null;
    $pagoId   = is_numeric($pagoIdRaw) ? (int) $pagoIdRaw : null;

    if (!$pedidoId) {
        $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
        if ($silent) return null;

        return response()->json([
            'ok' => true,
            'note' => 'no pedido_id in payment',
            'pedido_id_raw' => $pedidoIdRaw,
            'mp_payment_id' => $paymentId,
        ], 200);
    }

    // ✅ Necesitamos medio_pago_id sí o sí si alguna vez hay que crear pago en webhook
    $medioMp = DB::table('medios_pago')->where('codigo', 'mercadopago')->first();
    if (!$medioMp) {
        Log::error("Webhook: no existe medio de pago mercadopago en DB");
        $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
        if ($silent) return null;
        return response()->json(['ok' => false, 'note' => 'medio_pago_mercadopago_missing'], 200);
    }

    try {
        DB::transaction(function () use (
            $pedidoId,
            $pagoId,
            $paymentId,
            $status,
            $statusDetail,
            $merchantOrderId,
            $payment,
            $webhook,
            $medioMp
        ) {
            /** @var Pedido $pedido */
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);

            /** @var Pago|null $pago */
            $pago = null;

            // 1) Intentar por metadata.pago_id (mejor caso)
            if ($pagoId) {
                $pago = Pago::lockForUpdate()->find($pagoId);
            }

            // 2) Si no, tomar el último del pedido
            if (!$pago) {
                $pago = Pago::lockForUpdate()
                    ->where('pedido_id', $pedido->id)
                    ->orderByDesc('id')
                    ->first();
            }

            // 3) Si NO existe (casos raros), lo creamos con medio_pago_id obligatorio
            if (!$pago) {
                $pago = new Pago();
                $pago->pedido_id     = $pedido->id;
                $pago->medio_pago_id = $medioMp->id; // ✅ CLAVE
                $pago->estado        = 'iniciado';
                $pago->moneda        = data_get($payment, 'currency_id') ?: 'ARS';
                $pago->monto         = (float) (data_get($payment, 'transaction_amount') ?? $pedido->total_final ?? 0);
                $pago->save();
            }

            // 4) Actualizar datos del pago
            $nuevoEstadoPago = $this->mapMpStatusToPagoEstado($status);

            $pago->mp_payment_id        = $paymentId ?: $pago->mp_payment_id;
            $pago->mp_merchant_order_id = $merchantOrderId;
            $pago->mp_status            = $status;
            $pago->mp_status_detail     = $statusDetail;
            $pago->mp_raw_json          = json_encode($payment);
            $pago->moneda               = $pago->moneda ?: (data_get($payment, 'currency_id') ?: 'ARS');
            $pago->estado               = $nuevoEstadoPago;

            $ta = data_get($payment, 'transaction_amount');
            if ($ta !== null) {
                $pago->monto = (float) $ta;
            }
                Log::error('ESTADO PAGO DEBUG', [
                'mp_status' => $status,
                'nuevo_estado_pago' => $nuevoEstadoPago,
                'pedido_estado_actual' => $pedido->estado,
            ]);
            if ($nuevoEstadoPago === 'aprobado' && !$pago->aprobado_en) {
                $pago->aprobado_en = now();
            }

            $pago->save();

            // ========= SOLO SI APROBADO =========
            if ($nuevoEstadoPago === 'aprobado') {
                $yaPagado = ($pedido->estado === 'pagado');

                // A) Negocio (solo 1 vez)
                if (!$yaPagado) {
                    $pedido->estado = 'pagado';
                    $pedido->save();

                    // 1) Confirmar contenedor
                    try {
                        app(ContenedorReservaService::class)->confirmarPorPedido($pedido->id);
                    } catch (\Throwable $e) {
                        Log::error("Error confirmando servicio reserva contenedor", [
                            'pedido_id' => $pedido->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // 2) Stock real
                    $reservasStock = ReservaStock::lockForUpdate()
                        ->where('pedido_id', $pedido->id)
                        ->where('estado', 'activa')
                        ->get();

                    foreach ($reservasStock as $rs) {
                        $stock = StockSucursal::lockForUpdate()
                            ->where('producto_id', $rs->producto_id)
                            ->where('sucursal_id', $rs->sucursal_id)
                            ->first();

                        if (!$stock) continue;

                        if ((int)$stock->cantidad >= (int)$rs->cantidad) {
                            $stock->cantidad = (int)$stock->cantidad - (int)$rs->cantidad;
                            $stock->save();

                            $rs->estado = 'confirmada';
                            $rs->save();
                        } else {
                            Log::error("Stock insuficiente al confirmar", [
                                'pedido' => $pedido->id,
                                'prod' => $rs->producto_id
                            ]);
                        }
                    }

 }                   // 3) Factura Paljet
try {
    Log::error('VOY A LLAMAR A PALJET');
    app(\App\Services\PaljetService::class)->generarFacturaDePedido($pedido);
    Log::error('VOLVI DE PALJET');
} catch (\Throwable $e) {
    Log::error('EXPLOTO PALJET', [
        'mensaje' => $e->getMessage(),
        'archivo' => $e->getFile(),
        'linea'   => $e->getLine(),
    ]);
}

                // B) mails (tus funciones ya son anti-duplicados)
                $reservasContenedor = ContenedorReserva::lockForUpdate()
                    ->where('pedido_id', $pedido->id)
                    ->get();

                if ($reservasContenedor->isNotEmpty()) {
                    foreach ($reservasContenedor as $rc) {
                        $this->enviarMailsReservaContenedorPagada($pedido, $rc);
                    }
                } else {
                    $this->enviarMailsPedidoPagado($pedido);
                }
            }

            // ========= RECHAZADO/CANCELADO =========
            if (in_array($nuevoEstadoPago, ['rechazado', 'cancelado'], true)) {
                if ($pedido->estado !== 'fallido') {
                    $pedido->estado = 'fallido';
                    $pedido->save();
                }

                ReservaStock::where('pedido_id', $pedido->id)
                    ->where('estado', 'activa')
                    ->update(['estado' => 'liberada']);

                try {
                    app(ContenedorReservaService::class)->cancelarPorPedido($pedido->id);
                } catch (\Throwable $e) {
                    Log::error("Error cancelando reserva contenedor", [
                        'pedido_id' => $pedido->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ✅ marcar webhook procesado SIEMPRE
            $webhook->procesado = 1;
            $webhook->procesado_en = now();
            $webhook->save();
        });

    } catch (\Throwable $e) {
        Log::error('processPayment ERROR', [
            'error' => $e->getMessage(),
            'pedido_id' => $pedidoId,
            'pago_id' => $pagoId,
            'mp_payment_id' => $paymentId,
            'mp_status' => $status,
        ]);

        // si querés que MP reintente, devolvé 500 en el controller
        $webhook->update(['procesado' => 1, 'procesado_en' => now()]);

        if ($silent) return null;

        return response()->json([
            'ok' => false,
            'note' => 'processPayment_failed',
            'mp_payment_id' => $paymentId,
        ], 200);
    }

    if ($silent) return null;

    return response()->json([
        'ok' => true,
        'mp_payment_id' => $paymentId,
        'mp_status' => $status,
    ], 200);
}


private function enviarMailsPedidoPagado(Pedido $pedido): void
{
    $adminEmail   = config('mail.ferreteria.notif_email');
    $clienteEmail = $pedido->email_contacto;

    if (!$adminEmail && !$clienteEmail) return;

    /**
     * =========================
     * 1️⃣ MAIL CLIENTE
     * =========================
     */
    if ($clienteEmail) {

        $tomado = Pedido::where('id', $pedido->id)
            ->whereNull('email_cliente_enviado_at')
            ->update([
                'email_cliente_enviado_at' => now(),
                'mail_cliente_error_at' => null,
            ]);

        if ($tomado) {
            try {

                // 🔥 PEDIDO FRESH CON RELACIONES
                $pedidoFresh = Pedido::with([
                    'items',
                    'envio',
                    'pagos',
                    'sucursal'
                ])->find($pedido->id);

                Mail::mailer('pedidos')
                    ->to($clienteEmail)
                    ->send(new PedidoConfirmadoCliente($pedidoFresh));

            } catch (\Throwable $e) {

                Log::error("Error enviando mail cliente pedido {$pedido->id}", [
                    'email' => $clienteEmail,
                    'error' => $e->getMessage(),
                ]);

                // Rollback timestamp si falló SMTP
                Pedido::where('id', $pedido->id)->update([
                    'email_cliente_enviado_at' => null,
                    'mail_cliente_error_at' => now(),
                ]);
            }
        }
    }

    /**
     * =========================
     * 2️⃣ MAIL ADMIN
     * =========================
     */
    if ($adminEmail) {

        $tomado = Pedido::where('id', $pedido->id)
            ->whereNull('email_admin_enviado_at')
            ->update([
                'email_admin_enviado_at' => now(),
                'mail_admin_error_at' => null,
            ]);

        if ($tomado) {
            try {

                // 🔥 PEDIDO FRESH CON RELACIONES (+ cliente para admin)
                $pedidoFresh = Pedido::with([
                    'items',
                    'envio',
                    'pagos',
                    'sucursal',
                    'cliente'
                ])->find($pedido->id);

                Mail::mailer('pedidos')
                    ->to($adminEmail)
                    ->send(new PedidoConfirmadoAdmin($pedidoFresh));

            } catch (\Throwable $e) {

                Log::error("Error enviando mail admin pedido {$pedido->id}", [
                    'email' => $adminEmail,
                    'error' => $e->getMessage(),
                ]);

                // Rollback timestamp si falló SMTP
                Pedido::where('id', $pedido->id)->update([
                    'email_admin_enviado_at' => null,
                    'mail_admin_error_at' => now(),
                ]);
            }
        }
    }
}


    /**
     * ✅ Lógica idéntica para Reservas
     */
private function enviarMailsReservaContenedorPagada(Pedido $pedido, ContenedorReserva $reserva): void
{
    $adminEmail   = config('mail.ferreteria.notif_email');
    $clienteEmail = $pedido->email_contacto;

    if (!$adminEmail && !$clienteEmail) return;

    /**
     * Pedimos el nombre del producto (opcional)
     */
    $productoNombre = null;
    try {
        $productoNombre = Producto::find($reserva->producto_id)?->nombre;
    } catch (\Throwable $e) {
        // opcional: log si querés
    }

    /**
     * =========================
     * 1️⃣ MAIL CLIENTE
     * =========================
     */
    if ($clienteEmail) {

        $tomado = ContenedorReserva::where('id', $reserva->id)
            ->whereNull('email_enviado_at')
            ->update([
                'email_enviado_at' => now(),
                'mail_cliente_error_at' => null, // si tenés esta columna (si no, borrala)
            ]);

        if ($tomado) {
            try {

                // 🔥 PEDIDO FRESH + RESERVA FRESH
                $pedidoFresh = Pedido::with([
                    'items',
                    'envio',
                    'pagos',
                    'sucursal',
                    'cliente'
                ])->find($pedido->id);

                $reservaFresh = ContenedorReserva::find($reserva->id);

                Mail::mailer('pedidos')
                    ->to($clienteEmail)
                    ->send(new ContenedorReservaConfirmadaCliente($pedidoFresh, $reservaFresh, $productoNombre));

            } catch (\Throwable $e) {

                Log::error("Error enviando mail cliente reserva contenedor {$reserva->id}", [
                    'email' => $clienteEmail,
                    'error' => $e->getMessage(),
                ]);

                // Rollback timestamp si falló SMTP
                ContenedorReserva::where('id', $reserva->id)->update([
                    'email_enviado_at' => null,
                    'mail_cliente_error_at' => now(), // si no existe, borrala
                ]);
            }
        }
    }

    /**
     * =========================
     * 2️⃣ MAIL ADMIN
     * =========================
     */
    if ($adminEmail) {

        $tomado = ContenedorReserva::where('id', $reserva->id)
            ->whereNull('email_admin_enviado_at')
            ->update([
                'email_admin_enviado_at' => now(),
                'mail_admin_error_at' => null, // si no existe, borrala
            ]);

        if ($tomado) {
            try {

                // 🔥 PEDIDO FRESH + RESERVA FRESH
                $pedidoFresh = Pedido::with([
                    'items',
                    'envio',
                    'pagos',
                    'sucursal',
                    'cliente'
                ])->find($pedido->id);

                $reservaFresh = ContenedorReserva::find($reserva->id);

                Mail::mailer('pedidos')
                    ->to($adminEmail)
                    ->send(new ContenedorReservaConfirmadaAdmin($pedidoFresh, $reservaFresh, $productoNombre));

            } catch (\Throwable $e) {

                Log::error("Error enviando mail admin reserva contenedor {$reserva->id}", [
                    'email' => $adminEmail,
                    'error' => $e->getMessage(),
                ]);

                // Rollback timestamp si falló SMTP
                ContenedorReserva::where('id', $reserva->id)->update([
                    'email_admin_enviado_at' => null,
                    'mail_admin_error_at' => now(), // si no existe, borrala
                ]);
            }
        }
    }
}


    private function mapMpStatusToPagoEstado(string $mpStatus): string
    {
        return match ($mpStatus) {
            'approved' => 'aprobado',
            'pending', 'in_process' => 'pendiente',
            'rejected' => 'rechazado',
            'cancelled' => 'cancelado',
            default => 'pendiente',
        };
    }
}
