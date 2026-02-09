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
        // 1) Verificación de firma OBLIGATORIA
        $secret = config('services.mercadopago.webhook_secret');
        if (empty($secret)) {
            Log::error('MP Webhook: MP_WEBHOOK_SECRET no configurado. Rechazando webhook.');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        $signature = $request->header('x-signature');
        $requestId  = $request->header('x-request-id');

        if (!$signature || !$requestId) {
            Log::warning('MP Webhook: Petición sin firma o ID de request.');
            return response()->json(['error' => 'Signature or Request ID missing'], 400);
        }

        $payload = $request->getContent();
        $hmac = hash_hmac('sha256', "{$requestId}.{$payload}", $secret);

        $parts = explode(',', $signature);
        $receivedHash = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, 'v1=') === 0) {
                $receivedHash = substr($part, 3);
                break;
            }
        }

        if (!hash_equals($hmac, $receivedHash)) {
            Log::warning('Intento de webhook de Mercado Pago con firma inválida.', [
                'signature' => $signature,
                'id' => $requestId
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }


        $topic = $request->query('topic') ?? $request->input('type') ?? $request->query('type');
        $event = $request->query('type') ?? $request->input('action') ?? $request->query('action');

        $externalId =
            $request->query('id')
            ?? data_get($request->all(), 'data.id')
            ?? $request->input('id');

        $webhook = Webhook::create([
            'proveedor'    => 'mercadopago',
            'evento'       => (string)($topic ?: $event ?: 'unknown'),
            'external_id'  => $externalId ? (string)$externalId : null,
            'procesado'    => 0,
            'procesado_en' => null,
        ]);

        try {
            $mpAccessToken = config('services.mercadopago.access_token');
            if (!$mpAccessToken) {
                throw new \Exception('MP_ACCESS_TOKEN no configurado');
            }

            if ($topic === 'payment' || $request->input('type') === 'payment') {
                $paymentId = $externalId ?: data_get($request->all(), 'data.id');

                if (!$paymentId) {
                    $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
                    return response()->json(['ok' => true, 'note' => 'no payment id'], 200);
                }

                $payment = $this->mpGetPayment((string)$paymentId, $mpAccessToken);
                return $this->processPayment($payment, $webhook);
            }

            if ($topic === 'merchant_order') {
                $merchantOrderId = $externalId ?: data_get($request->all(), 'data.id');

                if (!$merchantOrderId) {
                    $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
                    return response()->json(['ok' => true, 'note' => 'no merchant_order id'], 200);
                }

                $merchantOrder = $this->mpGetMerchantOrder((string)$merchantOrderId, $mpAccessToken);

                $payments = data_get($merchantOrder, 'payments', []);
                if (!is_array($payments) || count($payments) === 0) {
                    $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
                    return response()->json(['ok' => true, 'note' => 'no payments in merchant_order'], 200);
                }

                foreach ($payments as $p) {
                    $pid = data_get($p, 'id');
                    if ($pid) {
                        $payment = $this->mpGetPayment((string)$pid, $mpAccessToken);
                        $this->processPayment($payment, $webhook, true);
                    }
                }

                $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
                return response()->json(['ok' => true], 200);
            }

            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
            return response()->json(['ok' => true, 'note' => 'topic not handled'], 200);
        } catch (\Throwable $e) {
            Log::error('MP WEBHOOK ERROR', [
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'webhook_id' => $webhook->id,
            ]);
            return response()->json(['ok' => false], 500);
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
    // 1. Extracción de datos
    $paymentId       = (string) data_get($payment, 'id');
    $status          = (string) data_get($payment, 'status');
    $statusDetail    = (string) data_get($payment, 'status_detail');
    $merchantOrderId = data_get($payment, 'order.id') ? (string) data_get($payment, 'order.id') : null;

    $pagoId   = data_get($payment, 'metadata.pago_id');
    $pedidoId = data_get($payment, 'metadata.pedido_id') ?: data_get($payment, 'external_reference');

    // 2. Validación básica
    if (!$pedidoId) {
        $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
        if ($silent) return null;
        return response()->json(['ok' => true, 'note' => 'no pedido_id in payment'], 200);
    }

    // 3. Transacción DB
    DB::transaction(function () use (
        $pedidoId,
        $pagoId,
        $paymentId,
        $status,
        $statusDetail,
        $merchantOrderId,
        $payment,
        $webhook
    ) {
        /** @var Pedido $pedido */
        $pedido = Pedido::lockForUpdate()->findOrFail((int)$pedidoId);

        /** @var Pago|null $pago */
        $pago = null;

        // Buscar Pago existente o el último asociado
        if ($pagoId) {
            $pago = Pago::lockForUpdate()->find((int)$pagoId);
        }

        if (!$pago) {
            $pago = Pago::lockForUpdate()
                ->where('pedido_id', $pedido->id)
                ->orderByDesc('id')
                ->first();
        }

        if (!$pago) {
            throw new \Exception("No se encontró pago para pedido_id={$pedido->id}");
        }

        // Actualizar datos del Pago
        $nuevoEstadoPago = $this->mapMpStatusToPagoEstado($status);

        $pago->mp_payment_id        = $paymentId ?: $pago->mp_payment_id;
        $pago->mp_merchant_order_id = $merchantOrderId;
        $pago->mp_status            = $status;
        $pago->mp_status_detail     = $statusDetail;
        $pago->mp_raw_json          = $payment;
        $pago->moneda               = $pago->moneda ?: (data_get($payment, 'currency_id') ?: 'ARS');
        $pago->estado               = $nuevoEstadoPago;

        if ($nuevoEstadoPago === 'aprobado' && !$pago->aprobado_en) {
            $pago->aprobado_en = now();
        }

        $pago->save();

        // ========= SOLO SI APROBADO =========
        if ($nuevoEstadoPago === 'aprobado') {

            $yaPagado = ($pedido->estado === 'pagado');

            // ✅ A) Lógica de Negocio (Stock, Factura, Servicio Contenedor)
            // Se ejecuta SOLO la primera vez que se aprueba
            if (!$yaPagado) {
                $pedido->estado = 'pagado';
                $pedido->save();

                // 1) Confirmar lógica de servicio de contenedores (Fechas, choferes, etc.)
                try {
                    app(ContenedorReservaService::class)->confirmarPorPedido($pedido->id);
                } catch (\Throwable $e) {
                    Log::error("Error confirmando servicio reserva contenedor", [
                        'pedido_id' => $pedido->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // 2) Descontar Stock Real (para productos físicos normales)
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
                        Log::error("Stock insuficiente al confirmar", ['pedido' => $pedido->id, 'prod' => $rs->producto_id]);
                    }
                }

                // 3) Generar Factura en Paljet
                try {
                    app(PaljetService::class)->generarFacturaDePedido($pedido);
                } catch (\Throwable $e) {
                    Log::error("Fallo al generar factura Paljet pedido {$pedido->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ✅ B) Lógica de Mails (IDEMPOTENTE Y EXCLUYENTE)
            // Esto se ejecuta siempre que el pago esté aprobado, pero los métodos internos
            // se protegen solos contra duplicados.
            
            // Buscamos si hay reservas de contenedor
            $reservasContenedor = ContenedorReserva::lockForUpdate()
                ->where('pedido_id', $pedido->id)
                ->get();

            if ($reservasContenedor->isNotEmpty()) {
                // CASO 1: Es Alquiler de Contenedor -> Solo mails de contenedor
                foreach ($reservasContenedor as $rc) {
                    $this->enviarMailsReservaContenedorPagada($pedido, $rc);
                }
            } else {
                // CASO 2: Es Compra Normal -> Solo mail de pedido estándar
                $this->enviarMailsPedidoPagado($pedido);
            }
        }

        // ========= SI RECHAZADO/CANCELADO =========
        if (in_array($nuevoEstadoPago, ['rechazado', 'cancelado'], true)) {
            if ($pedido->estado !== 'fallido') {
                $pedido->estado = 'fallido';
                $pedido->save();
            }

            // Liberar stock reservado
            ReservaStock::where('pedido_id', $pedido->id)
                ->where('estado', 'activa')
                ->update(['estado' => 'liberada']);

            // Cancelar servicio de contenedor
            try {
                app(ContenedorReservaService::class)->cancelarPorPedido($pedido->id);
            } catch (\Throwable $e) {
                Log::error("Error cancelando reserva contenedor", [
                    'pedido_id' => $pedido->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Marcar webhook como procesado
        $webhook->procesado = 1;
        $webhook->procesado_en = now();
        $webhook->save();
    });

    if ($silent) return null;

    return response()->json([
        'ok' => true,
        'mp_payment_id' => $paymentId,
        'mp_status'     => $status,
    ], 200);
}


    /**
     * ✅ ENVÍA 2 MAILS: cliente + admin. Anti-duplicados por timestamps en pedido.
     */
/**
     * ✅ ENVÍA 2 MAILS: cliente + admin.
     * SOLUCIÓN DUPLICADOS: Usamos update() con whereNull() para asegurar atomicidad.
     */
    private function enviarMailsPedidoPagado(Pedido $pedido): void
    {
        $adminEmail   = config('mail.ferreteria.notif_email');
        $clienteEmail = $pedido->email_contacto;

        if (!$adminEmail && !$clienteEmail) return;

        // No necesitamos refresh ni lockForUpdate aquí si usamos la técnica de abajo

        /**
         * 1. MAIL CLIENTE
         */
        if ($clienteEmail) {
            // INTENTO DE BLOQUEO:
            // "Actualizá la fecha AHORA, pero SOLO si antes estaba NULL".
            // Laravel nos devuelve cuántas filas modificó (1 o 0).
            $tomado = Pedido::where('id', $pedido->id)
                ->whereNull('email_cliente_enviado_at')
                ->update([
                    'email_cliente_enviado_at' => now(),
                    'mail_cliente_error_at' => null, 
                ]);

            // Si $tomado es 1, significa que este proceso GANÓ la carrera.
            // Si $tomado es 0, significa que otro proceso ya lo marcó, entonces no hacemos nada.
            if ($tomado) {
                try {
                    Mail::to($clienteEmail)->send(
                        new PedidoConfirmadoCliente($pedido)
                    );
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail cliente pedido {$pedido->id}", [
                        'email' => $clienteEmail,
                        'error' => $e->getMessage(),
                    ]);

                    // Si falló el envío real (SMTP caído, etc), volvemos a poner NULL
                    // para que se pueda reintentar en el futuro.
                    Pedido::where('id', $pedido->id)->update([
                        'email_cliente_enviado_at' => null, 
                        'mail_cliente_error_at' => now(),
                    ]);
                }
            }
        }

        /**
         * 2. MAIL ADMIN
         */
        if ($adminEmail) {
            // Misma lógica atómica para admin
            $tomado = Pedido::where('id', $pedido->id)
                ->whereNull('email_admin_enviado_at')
                ->update([
                    'email_admin_enviado_at' => now(),
                    'mail_admin_error_at' => null,
                ]);

            if ($tomado) {
                try {
                    Mail::to($adminEmail)->send(
                        new PedidoConfirmadoAdmin($pedido)
                    );
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail admin pedido {$pedido->id}", [
                        'email' => $adminEmail,
                        'error' => $e->getMessage(),
                    ]);

                    // Rollback del timestamp
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

        // Nombre del producto (opcional)
        $productoNombre = null;
        try {
            $productoNombre = Producto::find($reserva->producto_id)?->nombre;
        } catch (\Throwable $e) {}

        // ✅ Cliente
        if ($clienteEmail) {
            $tomado = ContenedorReserva::where('id', $reserva->id)
                ->whereNull('email_enviado_at')
                ->update(['email_enviado_at' => now()]);

            if ($tomado) {
                try {
                    Mail::to($clienteEmail)->send(
                        new ContenedorReservaConfirmadaCliente($pedido, $reserva, $productoNombre)
                    );
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail cliente reserva contenedor {$reserva->id}", [
                        'email' => $clienteEmail,
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Rollback
                    ContenedorReserva::where('id', $reserva->id)
                        ->update(['email_enviado_at' => null]);
                }
            }
        }

        // ✅ Admin
        if ($adminEmail) {
            // Asegurate que tu tabla tenga la columna email_admin_enviado_at
            $tomado = ContenedorReserva::where('id', $reserva->id)
                ->whereNull('email_admin_enviado_at')
                ->update(['email_admin_enviado_at' => now()]);

            if ($tomado) {
                try {
                    Mail::to($adminEmail)->send(
                        new ContenedorReservaConfirmadaAdmin($pedido, $reserva, $productoNombre)
                    );
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail admin reserva contenedor {$reserva->id}", [
                        'email' => $adminEmail,
                        'error' => $e->getMessage(),
                    ]);

                    // Rollback
                    ContenedorReserva::where('id', $reserva->id)
                        ->update(['email_admin_enviado_at' => null]);
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
