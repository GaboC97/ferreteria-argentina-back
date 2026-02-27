<?php

namespace App\Services;

use App\Models\ContenedorReserva;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\ReservaStock;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PagoService
{
    public function __construct(
        private ContenedorReservaService $contenedorReserva,
        private NotificacionService      $notificaciones,
        private PaljetService            $paljet,
    ) {}

    // =========================================================
    // REFUND MANUAL (desde PedidoController::devolver)
    // =========================================================

    /**
     * Procesa el refund de MercadoPago para un pago aprobado.
     * Debe llamarse dentro de la transacción de devolución.
     *
     * Retorna ['mp_refund' => bool, 'detalle' => string|null]
     */
    public function procesarRefundMP(Pago $pago): array
    {
        if ($pago->mp_refund_id) {
            return [
                'mp_refund' => true,
                'detalle'   => 'Refund ya procesado anteriormente (mp_refund_id: ' . $pago->mp_refund_id . ')',
            ];
        }

        $mpToken = config('services.mercadopago.access_token');

        if (!$mpToken) {
            return [
                'mp_refund' => false,
                'detalle'   => 'MP_ACCESS_TOKEN no configurado. Refund pendiente de gestión manual.',
            ];
        }

        $resp = Http::withToken($mpToken)
            ->acceptJson()
            ->post("https://api.mercadopago.com/v1/payments/{$pago->mp_payment_id}/refunds");

        if ($resp->successful()) {
            $mpRefund = $resp->json();

            $pago->update([
                'mp_refund_id'  => data_get($mpRefund, 'id'),
                'refund_monto'  => data_get($mpRefund, 'amount', $pago->monto),
                'refund_status' => data_get($mpRefund, 'status', 'approved'),
                'devuelto_en'   => now(),
            ]);

            return [
                'mp_refund' => true,
                'detalle'   => 'Refund procesado en MercadoPago (refund_id: ' . data_get($mpRefund, 'id') . ')',
            ];
        }

        Log::error('MP refund error', [
            'mp_payment_id' => $pago->mp_payment_id,
            'status'        => $resp->status(),
            'body'          => $resp->body(),
        ]);

        return [
            'mp_refund' => false,
            'detalle'   => 'Error al procesar refund en MercadoPago. La devolución interna se realizó, pero el reembolso del dinero debe gestionarse manualmente.',
        ];
    }

    // =========================================================
    // WEBHOOK (desde WebhookController)
    // =========================================================

    /**
     * Obtiene un pago de la API de MercadoPago.
     * Lanza excepción si falla.
     */
    public function obtenerPagoMP(string $paymentId): array
    {
        $resp = Http::withToken(config('services.mercadopago.access_token'))
            ->acceptJson()
            ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

        if (!$resp->successful()) {
            throw new \Exception("Error MP get payment {$paymentId}: " . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

    /**
     * Obtiene un merchant order de la API de MercadoPago.
     * Lanza excepción si falla.
     */
    public function obtenerMerchantOrderMP(string $merchantOrderId): array
    {
        $resp = Http::withToken(config('services.mercadopago.access_token'))
            ->acceptJson()
            ->get("https://api.mercadopago.com/merchant_orders/{$merchantOrderId}");

        if (!$resp->successful()) {
            throw new \Exception("Error MP get merchant_order {$merchantOrderId}: " . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

    /**
     * Procesa un evento de pago recibido desde el webhook de MercadoPago.
     *
     * Retorna ['ok' => bool, 'mp_payment_id' => string|null, 'mp_status' => string|null, 'note' => string|null]
     */
    public function procesarPagoMP(array $payment, Webhook $webhook): array
    {
        $paymentId       = (string) data_get($payment, 'id');
        $status          = (string) data_get($payment, 'status');
        $statusDetail    = (string) data_get($payment, 'status_detail');
        $merchantOrderId = data_get($payment, 'order.id') ? (string) data_get($payment, 'order.id') : null;

        $pagoIdRaw   = data_get($payment, 'metadata.pago_id');
        $pedidoIdRaw = data_get($payment, 'metadata.pedido_id') ?: data_get($payment, 'external_reference');

        $pedidoId = is_numeric($pedidoIdRaw) ? (int) $pedidoIdRaw : null;
        $pagoId   = is_numeric($pagoIdRaw)   ? (int) $pagoIdRaw   : null;

        if (!$pedidoId) {
            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
            return [
                'ok'            => true,
                'note'          => 'no pedido_id in payment',
                'mp_payment_id' => $paymentId,
                'mp_status'     => $status,
            ];
        }

        $medioMp = DB::table('medios_pago')->where('codigo', 'mercadopago')->first();
        if (!$medioMp) {
            Log::error("Webhook: no existe medio de pago mercadopago en DB");
            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
            return ['ok' => false, 'note' => 'medio_pago_mercadopago_missing'];
        }

        try {
            DB::transaction(function () use (
                $pedidoId, $pagoId, $paymentId, $status, $statusDetail,
                $merchantOrderId, $payment, $webhook, $medioMp
            ) {
                /** @var Pedido $pedido */
                $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);

                /** @var Pago|null $pago */
                $pago = null;

                if ($pagoId) {
                    $pago = Pago::lockForUpdate()->find($pagoId);
                }

                if (!$pago) {
                    $pago = Pago::lockForUpdate()
                        ->where('pedido_id', $pedido->id)
                        ->orderByDesc('id')
                        ->first();
                }

                if (!$pago) {
                    $pago                = new Pago();
                    $pago->pedido_id     = $pedido->id;
                    $pago->medio_pago_id = $medioMp->id;
                    $pago->estado        = 'iniciado';
                    $pago->moneda        = data_get($payment, 'currency_id') ?: 'ARS';
                    $pago->monto         = (float) (data_get($payment, 'transaction_amount') ?? $pedido->total_final ?? 0);
                    $pago->save();
                }

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
                    'mp_status'            => $status,
                    'nuevo_estado_pago'    => $nuevoEstadoPago,
                    'pedido_estado_actual' => $pedido->estado,
                ]);

                if ($nuevoEstadoPago === 'aprobado' && !$pago->aprobado_en) {
                    $pago->aprobado_en = now();
                }

                $pago->save();

                // ========= APROBADO =========
                if ($nuevoEstadoPago === 'aprobado') {

                    if ($pedido->estado !== 'pagado') {

                        Log::info('VOY A LLAMAR A PALJET', ['pedido_id' => $pedido->id]);

                        $paljetPedidoId = $this->paljet->generarFacturaDePedido($pedido);

                        if (!$paljetPedidoId) {
                            throw new \Exception("Paljet no generó el pedido para pedido {$pedido->id}");
                        }

                        Log::info('PALJET OK', [
                            'pedido_id'        => $pedido->id,
                            'paljet_pedido_id' => $paljetPedidoId,
                        ]);

                        $pedido->paljet_pedido_id = $paljetPedidoId;
                        $pedido->estado           = 'pagado';
                        $pedido->save();

                        try {
                            $this->contenedorReserva->confirmarPorPedido($pedido->id);
                        } catch (\Throwable $e) {
                            Log::error("Error confirmando reserva contenedor", [
                                'pedido_id' => $pedido->id,
                                'error'     => $e->getMessage(),
                            ]);
                        }
                    }

                    $reservasContenedor = ContenedorReserva::lockForUpdate()
                        ->where('pedido_id', $pedido->id)
                        ->get();

                    if ($reservasContenedor->isNotEmpty()) {
                        foreach ($reservasContenedor as $rc) {
                            $this->notificaciones->enviarMailsReservaContenedorPagada($pedido, $rc);
                        }
                    } else {
                        $this->notificaciones->enviarMailsPedidoPagado($pedido);
                    }
                }

                // ========= RECHAZADO / CANCELADO =========
                if (in_array($nuevoEstadoPago, ['rechazado', 'cancelado'], true)) {
                    if ($pedido->estado !== 'fallido') {
                        $pedido->estado = 'fallido';
                        $pedido->save();
                    }

                    ReservaStock::where('pedido_id', $pedido->id)
                        ->where('estado', 'activa')
                        ->update(['estado' => 'liberada']);

                    try {
                        $this->contenedorReserva->cancelarPorPedido($pedido->id);
                    } catch (\Throwable $e) {
                        Log::error("Error cancelando reserva contenedor", [
                            'pedido_id' => $pedido->id,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                $webhook->procesado    = 1;
                $webhook->procesado_en = now();
                $webhook->save();
            });

        } catch (\Throwable $e) {
            Log::error('processPayment ERROR', [
                'error'         => $e->getMessage(),
                'pedido_id'     => $pedidoId,
                'pago_id'       => $pagoId,
                'mp_payment_id' => $paymentId,
                'mp_status'     => $status,
            ]);

            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);

            return [
                'ok'            => false,
                'note'          => 'processPayment_failed',
                'mp_payment_id' => $paymentId,
                'mp_status'     => $status,
            ];
        }

        return [
            'ok'            => true,
            'mp_payment_id' => $paymentId,
            'mp_status'     => $status,
        ];
    }

    private function mapMpStatusToPagoEstado(string $mpStatus): string
    {
        return match ($mpStatus) {
            'approved'               => 'aprobado',
            'pending', 'in_process'  => 'pendiente',
            'rejected'               => 'rechazado',
            'cancelled'              => 'cancelado',
            default                  => 'pendiente',
        };
    }
}
