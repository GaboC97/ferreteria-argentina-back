<?php

namespace App\Services;

use App\Models\ContenedorReserva;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\ReservaStock;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PagoService
{
    public function __construct(
        private ContenedorReservaService $contenedorReserva,
        private NotificacionService      $notificaciones,
        private PaljetService            $paljet,
        private GetnetService            $getnet,
    ) {}

    // =========================================================
    // GETNET: CONSULTA DE PAGO
    // =========================================================

    /**
     * Obtiene los datos de un pago desde la API de Getnet.
     * Lanza excepción si falla.
     */
    public function obtenerPagoGetnet(string $paymentId): array
    {
        return $this->getnet->obtenerPago($paymentId);
    }

    // =========================================================
    // GETNET: WEBHOOK
    // =========================================================

    /**
     * Procesa un evento de pago recibido desde el webhook de Getnet.
     * Mantiene toda la lógica de Paljet, stock, contenedores y notificaciones.
     */
    public function procesarPagoGetnet(array $payment, Webhook $webhook): array
    {
        // Getnet Web Checkout response:
        // payment_intent_id, order_id, payment.result.status = "Authorized" | "Denied"
        // payment.result.payment_id = UUID del pago procesado
        $paymentIntentId = (string) (data_get($payment, 'payment_intent_id') ?? '');
        $orderId         = data_get($payment, 'order_id') ?? null;
        $status          = (string) (data_get($payment, 'payment.result.status')
            ?? data_get($payment, 'status')
            ?? 'Pending');
        $paymentResultId = data_get($payment, 'payment.result.payment_id') ?? $paymentIntentId;

        // Buscar el pago local por payment_intent_id (guardado en getnet_payment_id) o order_id
        $pagoLocal = null;
        if ($paymentIntentId) {
            $pagoLocal = Pago::where('getnet_payment_id', $paymentIntentId)->orderByDesc('id')->first();
        }
        if (!$pagoLocal && $orderId) {
            $pagoLocal = Pago::where('getnet_order_id', $orderId)->orderByDesc('id')->first();
        }

        $pedidoId = null;

        if ($pagoLocal) {
            $pedidoId = (int) $pagoLocal->pedido_id;
        }

        if (!$pedidoId) {
            Log::warning('Getnet Webhook: no se pudo encontrar pedido_id', [
                'payment_intent_id' => $paymentIntentId,
                'order_id'          => $orderId,
            ]);
            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
            return ['ok' => true, 'note' => 'no pedido_id found', 'getnet_payment_id' => $paymentIntentId];
        }

        $medioGetnet = DB::table('medios_pago')->where('codigo', 'getnet')->first();
        if (!$medioGetnet) {
            Log::error('Webhook: no existe medio de pago getnet en DB');
            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);
            return ['ok' => false, 'note' => 'medio_pago_getnet_missing'];
        }

        try {
            DB::transaction(function () use (
                $pedidoId, $paymentIntentId, $paymentResultId, $orderId, $status,
                $payment, $webhook, $medioGetnet, $pagoLocal
            ) {
                /** @var Pedido $pedido */
                $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);

                /** @var Pago $pago */
                $pago = $pagoLocal
                    ? Pago::lockForUpdate()->find($pagoLocal->id)
                    : null;

                if (!$pago) {
                    $pago                = new Pago();
                    $pago->pedido_id     = $pedido->id;
                    $pago->medio_pago_id = $medioGetnet->id;
                    $pago->estado        = 'iniciado';
                    $pago->moneda        = config('getnet.currency', 'ARS');
                    $pago->monto         = (float) ($pedido->total_final ?? 0);
                    $pago->save();
                }

                $nuevoEstadoPago = $this->mapGetnetStatusToPagoEstado($status);

                // El monto viene en centavos en payment.amount
                $monto = data_get($payment, 'payment.amount') ?? data_get($payment, 'amount');
                if ($monto !== null) {
                    $pago->monto = (float) $monto / 100;
                }

                $pago->getnet_payment_id = $paymentIntentId ?: $pago->getnet_payment_id;
                $pago->getnet_order_id   = $orderId         ?: $pago->getnet_order_id;
                $pago->getnet_status     = $status;
                $pago->getnet_raw_json   = json_encode(Pago::sanitizeGetnetRaw((array) $payment));
                $pago->estado            = $nuevoEstadoPago;

                if ($nuevoEstadoPago === 'aprobado' && !$pago->aprobado_en) {
                    $pago->aprobado_en = now();
                }

                $pago->save();

                Log::info('Getnet Webhook: estado procesado', [
                    'getnet_status'     => $status,
                    'nuevo_estado'      => $nuevoEstadoPago,
                    'pedido_estado'     => $pedido->estado,
                    'pedido_id'         => $pedidoId,
                    'payment_intent_id' => $paymentIntentId,
                ]);

                // ========= APROBADO =========
                if ($nuevoEstadoPago === 'aprobado') {

                    if ($pedido->estado !== 'pagado') {

                        Log::info('Getnet: generando pedido en Paljet', ['pedido_id' => $pedido->id]);

                        $paljetPedidoId = $this->paljet->generarFacturaDePedido($pedido);

                        if (!$paljetPedidoId) {
                            throw new \Exception("Paljet no generó el pedido para pedido {$pedido->id}");
                        }

                        Log::info('Paljet OK', [
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
            Log::error('Getnet procesarPago ERROR', [
                'error'      => $e->getMessage(),
                'pedido_id'  => $pedidoId,
                'payment_id' => $paymentId,
                'status'     => $status,
            ]);

            $webhook->update(['procesado' => 1, 'procesado_en' => now()]);

            return [
                'ok'                => false,
                'note'              => 'procesarPago_failed',
                'getnet_payment_id' => $paymentId,
                'getnet_status'     => $status,
            ];
        }

        return [
            'ok'                => true,
            'getnet_payment_id' => $paymentId,
            'getnet_status'     => $status,
        ];
    }

    // =========================================================
    // GETNET: REFUND (desde PedidoController::devolver)
    // =========================================================

    /**
     * Procesa el refund en Getnet para un pago aprobado.
     * Debe llamarse dentro de la transacción de devolución.
     */
    public function procesarRefundGetnet(Pago $pago): array
    {
        if ($pago->refund_status === 'approved') {
            return [
                'refund' => true,
                'detalle' => 'Refund ya procesado anteriormente.',
            ];
        }

        if (!$pago->getnet_payment_id) {
            return [
                'refund'  => false,
                'detalle' => 'No hay payment_id de Getnet. El reembolso debe gestionarse manualmente.',
            ];
        }

        try {
            $respGetnet = $this->getnet->procesarRefund($pago->getnet_payment_id, (float) $pago->monto);

            $pago->update([
                'refund_monto'  => data_get($respGetnet, 'amount') ? (float) data_get($respGetnet, 'amount') / 100 : $pago->monto,
                'refund_status' => data_get($respGetnet, 'status', 'approved'),
                'devuelto_en'   => now(),
            ]);

            return [
                'refund'  => true,
                'detalle' => 'Refund procesado en Getnet.',
            ];
        } catch (\Throwable $e) {
            Log::error('Getnet refund error', [
                'payment_id' => $pago->getnet_payment_id,
                'error'      => $e->getMessage(),
            ]);

            return [
                'refund'  => false,
                'detalle' => 'Error al procesar refund en Getnet. La devolución interna se realizó, pero el reembolso del dinero debe gestionarse manualmente.',
            ];
        }
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function mapGetnetStatusToPagoEstado(string $status): string
    {
        return match ($status) {
            'Authorized'                          => 'aprobado',
            'Pending', 'initiated'                => 'pendiente',
            'Denied', 'Rejected', 'Error'         => 'rechazado',
            'Cancelled', 'Canceled'               => 'cancelado',
            default                               => 'pendiente',
        };
    }
}
