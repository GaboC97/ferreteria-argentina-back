<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetnetService
{
    private string $baseUrl;
    private string $webBaseUrl;
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->baseUrl      = config('getnet.base_url', 'https://api.globalgetnet.com');
        $this->webBaseUrl   = config('getnet.web_base_url', 'https://www.globalgetnet.com');
        $this->clientId     = config('getnet.client_id');
        $this->clientSecret = config('getnet.client_secret');
    }

    /**
     * Obtiene un Bearer token vía OAuth2 Client Credentials.
     * Se cachea por 58 minutos (Getnet da 3599 segundos ~= 1 hora).
     * Credenciales van en el body del form (no en Basic Auth header).
     */
    public function getAccessToken(): string
    {
        return Cache::remember('getnet_access_token', config('getnet.token_ttl'), function () {
            $resp = Http::asForm()->post("{$this->baseUrl}/authentication/oauth2/access_token", [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if (!$resp->successful()) {
                Log::error('Getnet auth error', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                throw new \Exception('No se pudo autenticar con Getnet: ' . $resp->status());
            }

            return $resp->json()['access_token'];
        });
    }

    /**
     * Crea un Payment Intent en Getnet Web Checkout.
     * Endpoint: POST /digital-checkout/v1/payment-intent
     */
    public function crearPaymentIntent(array $payload): array
    {
        $token = $this->getAccessToken();

        $resp = Http::withToken($token)
            ->acceptJson()
            ->post("{$this->baseUrl}/digital-checkout/v1/payment-intent", $payload);

        if (!$resp->successful()) {
            Log::error('Getnet payment-intent error', [
                'status'  => $resp->status(),
                'body'    => $resp->body(),
                'payload' => $payload,
            ]);
            throw new \Exception('Error al crear payment intent en Getnet: ' . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

    /**
     * Consulta el estado de un payment intent en Getnet.
     */
    public function obtenerPaymentIntent(string $paymentIntentId): array
    {
        $token = $this->getAccessToken();

        $resp = Http::withToken($token)
            ->acceptJson()
            ->get("{$this->baseUrl}/digital-checkout/v1/payment-intent/{$paymentIntentId}");

        if (!$resp->successful()) {
            throw new \Exception("Error Getnet get payment-intent {$paymentIntentId}: " . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

    /**
     * Procesa un refund para un pago aprobado.
     */
    public function procesarRefund(string $paymentId, float $monto): array
    {
        $token = $this->getAccessToken();

        $resp = Http::withToken($token)
            ->acceptJson()
            ->post("{$this->baseUrl}/digital-checkout/v1/payments/{$paymentId}/refund", [
                'amount' => (int) round($monto * 100),
            ]);

        if (!$resp->successful()) {
            Log::error('Getnet refund error', [
                'payment_id' => $paymentId,
                'status'     => $resp->status(),
                'body'       => $resp->body(),
            ]);
            throw new \Exception('Error al procesar refund en Getnet: ' . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json();
    }

    /**
     * URL base del frontend SDK de Getnet (para loader.js).
     */
    public function getLoaderScriptUrl(): string
    {
        return "{$this->webBaseUrl}/digital-checkout/loader.js";
    }
}
