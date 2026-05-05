<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
use App\Services\PagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private PagoService $pagoService) {}

    public function getnet(Request $request)
    {
        $rawBody  = $request->getContent() ?: '';
        $payload  = $request->all();
        $evento   = data_get($payload, 'event') ?? data_get($payload, 'type') ?? 'unknown';
        $extId    = data_get($payload, 'data.payment_id')
            ?? data_get($payload, 'payment_id')
            ?? data_get($payload, 'id')
            ?? null;

        // 0) Guardar webhook siempre
        try {
            $webhook = Webhook::create([
                'proveedor'    => 'getnet',
                'evento'       => (string) $evento,
                'external_id'  => $extId ? (string) $extId : null,
                'payload_json' => $rawBody,
                'procesado'    => 0,
                'procesado_en' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Getnet Webhook: no se pudo guardar en tabla webhooks', ['error' => $e->getMessage()]);
            $webhook = new \stdClass();
            $webhook->id        = null;
            $webhook->procesado = 0;
        }

        // 1) Validar firma HMAC si hay secret configurado
        $secret    = config('getnet.webhook_secret');
        $signature = $request->header('x-getnet-signature') ?? $request->header('x-signature');

        if (!empty($secret) && !empty($signature)) {
            $computed = hash_hmac('sha256', $rawBody, $secret);
            if (!hash_equals(strtolower($computed), strtolower($signature))) {
                Log::warning('Getnet Webhook: firma inválida', [
                    'webhook_id' => $webhook->id ?? null,
                    'computed'   => $computed,
                    'received'   => $signature,
                ]);
                // Modo tolerante: logueamos pero seguimos procesando
            }
        }

        // 2) Procesar
        try {
            $paymentId = data_get($payload, 'data.payment_id')
                ?? data_get($payload, 'payment_id')
                ?? data_get($payload, 'data.id')
                ?? null;

            if (!$paymentId) {
                if ($webhook->id) {
                    Webhook::where('id', $webhook->id)->update(['procesado' => 1, 'procesado_en' => now()]);
                }
                return response()->json(['ok' => true, 'note' => 'no payment_id'], 200);
            }

            // Obtener datos del pago desde Getnet y procesar
            $payment = $this->pagoService->obtenerPagoGetnet((string) $paymentId);
            $result  = $this->pagoService->procesarPagoGetnet($payment, $webhook);

            return response()->json($result, $result['ok'] ? 200 : 500);

        } catch (\Throwable $e) {
            Log::error('GETNET WEBHOOK ERROR', [
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'webhook_id' => $webhook->id ?? null,
            ]);

            return response()->json(['ok' => false], 500);
        }
    }
}
