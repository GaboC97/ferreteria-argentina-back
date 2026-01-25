<?php

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Models\Pedido;
use App\Models\ReservaStock;
use App\Models\StockSucursal;
use App\Models\Webhook;
use App\Services\ContenedorReservaService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class WebhookController extends Controller
{
    public function mercadoPago(Request $request)
    {
        $payload = [
            'query'   => $request->query(),
            'body'    => $request->all(),
            'headers' => $request->headers->all(),
        ];

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
            'payload_json' => $payload,
            'procesado'    => 0,
            'procesado_en' => null,
        ]);

        try {
            $mpAccessToken = config('services.mercadopago.access_token');
            if (!$mpAccessToken) {
                throw new \Exception('MP_ACCESS_TOKEN no configurado');
            }

            // MP puede mandar "type=payment" (body) o "topic=payment" (query)
            if ($topic === 'payment' || $request->input('type') === 'payment') {
                $paymentId = $externalId ?: data_get($request->all(), 'data.id');

                if (!$paymentId) {
                    $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
                    return response()->json(['ok' => true, 'note' => 'no payment id'], 200);
                }

                $payment = $this->mpGetPayment((string)$paymentId, $mpAccessToken);
                return $this->processPayment($payment, $webhook);
            }

            // A veces llega merchant_order: traemos sus payments y procesamos
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

            // Mercado Pago reintenta si devolvés 500; pero igual suele reintentar aunque sea 200.
            // Mantenemos 200 para no entrar en bucle de reintentos mientras debuggeás.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /* ===================== MP API HELPERS ===================== */

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

    /* ===================== PROCESS LOGIC ===================== */

    private function processPayment(array $payment, Webhook $webhook, bool $silent = false)
    {
        $paymentId       = (string) data_get($payment, 'id');
        $status          = (string) data_get($payment, 'status');
        $statusDetail    = (string) data_get($payment, 'status_detail');
        $merchantOrderId = data_get($payment, 'order.id') ? (string) data_get($payment, 'order.id') : null;

        $pagoId   = data_get($payment, 'metadata.pago_id');
        $pedidoId = data_get($payment, 'metadata.pedido_id');

        // Fallback: Checkout Pro muchas veces trae external_reference = pedido_id
        if (!$pedidoId) {
            $pedidoId = data_get($payment, 'external_reference');
        }

        if (!$pedidoId) {
            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
            if ($silent) return null;
            return response()->json(['ok' => true, 'note' => 'no pedido_id in payment'], 200);
        }

        DB::transaction(function () use (
            $pedidoId, $pagoId, $paymentId, $status, $statusDetail, $merchantOrderId, $payment, $webhook
        ) {
            /** @var Pedido $pedido */
            $pedido = Pedido::lockForUpdate()->findOrFail((int)$pedidoId);

            /** @var Pago|null $pago */
            $pago = null;

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

            // ✅ Guardamos estado MP siempre (aunque no apliquemos efectos de negocio)
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

            // ============================================================
            // ✅ IDEMPOTENCIA DE NEGOCIO
            // Si el pedido ya está pagado, NO volvemos a descontar stock
            // ni a confirmar reservas.
            // ============================================================
            if ($nuevoEstadoPago === 'aprobado') {

                if ($pedido->estado === 'pagado') {
                    // Ya aplicado antes => no repetir efectos
                    $webhook->procesado = 1;
                    $webhook->procesado_en = now();
                    $webhook->save();
                    return;
                }

                // 1) Pedido pagado
                $pedido->estado = 'pagado';
                $pedido->save();

                // 2) Confirmar reservas operativas de contenedor
                try {
                    app(ContenedorReservaService::class)->confirmarPorPedido($pedido->id);
                } catch (\Throwable $e) {
                    // No tiramos abajo el webhook por un tema operativo
                    Log::error("Error confirmando reserva contenedor", [
                        'pedido_id' => $pedido->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // 3) Confirmar reservas de stock (y descontar stock_sucursal)
                $reservas = ReservaStock::lockForUpdate()
                    ->where('pedido_id', $pedido->id)
                    ->where('estado', 'activa')
                    ->get();

                foreach ($reservas as $reserva) {
                    $stock = StockSucursal::lockForUpdate()
                        ->where('producto_id', $reserva->producto_id)
                        ->where('sucursal_id', $reserva->sucursal_id)
                        ->first();

                    if (!$stock) {
                        Log::error("Falta stock_sucursal al confirmar", [
                            'pedido_id'   => $pedido->id,
                            'producto_id' => $reserva->producto_id,
                            'sucursal_id' => $reserva->sucursal_id,
                        ]);
                        // No cortamos todo el webhook
                        continue;
                    }

                    if ((int)$stock->cantidad < (int)$reserva->cantidad) {
                        Log::error("Stock insuficiente al confirmar (inconsistencia)", [
                            'pedido_id'   => $pedido->id,
                            'producto_id' => $reserva->producto_id,
                            'sucursal_id' => $reserva->sucursal_id,
                            'stock'       => (int)$stock->cantidad,
                            'reserva'     => (int)$reserva->cantidad,
                        ]);
                        // No cortamos todo el webhook
                        continue;
                    }

                    $stock->cantidad = (int)$stock->cantidad - (int)$reserva->cantidad;
                    $stock->save();

                    $reserva->estado = 'confirmada';
                    $reserva->save();
                }
            }

            // ❌ RECHAZADO / CANCELADO (liberar reservas + marcar pedido fallido)
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
