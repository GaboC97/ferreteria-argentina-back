<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
use App\Services\PagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private PagoService $pagoService) {}

    public function mercadoPago(Request $request)
    {
        $topic      = $request->query('topic') ?? $request->input('type') ?? $request->query('type');
        $event      = $request->query('type')  ?? $request->input('action') ?? $request->query('action');
        $externalId = $request->query('id')    ?? data_get($request->all(), 'data.id') ?? $request->input('id');
        $rawBody    = $request->getContent() ?: '';

        // 0) Guardar webhook SIEMPRE
        try {
            $webhook = Webhook::create([
                'proveedor'    => 'mercadopago',
                'evento'       => (string)($topic ?: $event ?: 'unknown'),
                'external_id'  => $externalId ? (string)$externalId : null,
                'payload_json' => $rawBody,
                'procesado'    => 0,
                'procesado_en' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('MP Webhook: no se pudo guardar en tabla webhooks', ['error' => $e->getMessage()]);
            $webhook = new \stdClass();
            $webhook->id        = null;
            $webhook->procesado = 0;
        }

        // 1) Validación de firma (modo tolerante)
        $secret     = config('services.mercadopago.webhook_secret');
        $xSignature = $request->header('x-signature');
        $xRequestId = $request->header('x-request-id');

        $firmaOk        = false;
        $firmaIntentada = false;

        if (!empty($secret) && !empty($xSignature) && !empty($xRequestId) && !empty($externalId)) {
            $firmaIntentada = true;
            $ts = null;
            $v1 = null;

            foreach (explode(',', $xSignature) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 'ts=')) $ts = substr($part, 3);
                if (str_starts_with($part, 'v1=')) $v1 = substr($part, 3);
            }

            if (!empty($ts) && !empty($v1)) {
                $manifest = "id:{$externalId};request-id:{$xRequestId};ts:{$ts};";
                $computed = hash_hmac('sha256', $manifest, $secret);
                $firmaOk  = hash_equals(strtolower($computed), strtolower($v1));

                if (!$firmaOk) {
                    Log::warning('MP Webhook: firma inválida (modo tolerante, seguimos igual)', [
                        'webhook_id'   => $webhook->id ?? null,
                        'manifest'     => $manifest,
                        'computed'     => $computed,
                        'received'     => $v1,
                        'x_request_id' => $xRequestId,
                        'x_signature'  => $xSignature,
                    ]);
                    // return response()->json(['error' => 'Invalid signature'], 400); // MODO ESTRICTO
                }
            } else {
                Log::warning('MP Webhook: formato x-signature inválido', [
                    'webhook_id'  => $webhook->id ?? null,
                    'x_signature' => $xSignature,
                ]);
            }
        } elseif (empty($secret)) {
            Log::warning('MP Webhook: MP_WEBHOOK_SECRET no configurado (modo tolerante)', [
                'webhook_id' => $webhook->id ?? null,
            ]);
        }

        // 2) Procesamiento
        try {
            if (!config('services.mercadopago.access_token')) {
                throw new \Exception('MP_ACCESS_TOKEN no configurado');
            }

            $isPayment       = ($topic === 'payment') || ($request->input('type') === 'payment');
            $isMerchantOrder = ($topic === 'merchant_order');

            if ($isPayment) {
                $paymentId = $externalId ?: data_get($request->all(), 'data.id');

                if (!$paymentId) {
                    if ($webhook->id) {
                        Webhook::where('id', $webhook->id)->update(['procesado' => 1, 'procesado_en' => now()]);
                    }
                    return response()->json(['ok' => true, 'note' => 'no payment id'], 200);
                }

                $payment = $this->pagoService->obtenerPagoMP((string)$paymentId);
                $result  = $this->pagoService->procesarPagoMP($payment, $webhook);

                return response()->json($result, $result['ok'] ? 200 : 500);
            }

            if ($isMerchantOrder) {
                $merchantOrderId = $externalId ?: data_get($request->all(), 'data.id');

                if (!$merchantOrderId) {
                    if ($webhook->id) {
                        Webhook::where('id', $webhook->id)->update(['procesado' => 1, 'procesado_en' => now()]);
                    }
                    return response()->json(['ok' => true, 'note' => 'no merchant_order id'], 200);
                }

                $merchantOrder = $this->pagoService->obtenerMerchantOrderMP((string)$merchantOrderId);
                $payments      = data_get($merchantOrder, 'payments', []);

                if (!is_array($payments) || count($payments) === 0) {
                    if ($webhook->id) {
                        Webhook::where('id', $webhook->id)->update(['procesado' => 1, 'procesado_en' => now()]);
                    }
                    return response()->json(['ok' => true, 'note' => 'no payments in merchant_order'], 200);
                }

                foreach ($payments as $p) {
                    $pid = data_get($p, 'id');
                    if ($pid) {
                        $payment = $this->pagoService->obtenerPagoMP((string)$pid);
                        $this->pagoService->procesarPagoMP($payment, $webhook);
                    }
                }

                if ($webhook->id) {
                    Webhook::where('id', $webhook->id)->update(['procesado' => 1, 'procesado_en' => now()]);
                }

                return response()->json(['ok' => true], 200);
            }

            if ($webhook->id) {
                Webhook::where('id', $webhook->id)->update(['procesado' => 1, 'procesado_en' => now()]);
            }

            return response()->json(['ok' => true, 'note' => 'topic not handled'], 200);

        } catch (\Throwable $e) {
            Log::error('MP WEBHOOK ERROR', [
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
                'webhook_id'      => $webhook->id ?? null,
                'firma_intentada' => $firmaIntentada,
                'firma_ok'        => $firmaOk,
            ]);

            return response()->json(['ok' => false], 500);
        }
    }
}
