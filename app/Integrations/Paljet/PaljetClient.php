<?php

namespace App\Integrations\Paljet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaljetClient
{
    private string $baseUrl;
    private string $empId;
    private string $user;
    private string $pass;
    private int $timeout;

    public function __construct()
    {
        $cfg = config('services.paljet');

        $this->baseUrl  = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $this->empId    = (string)($cfg['emp_id'] ?? '');
        $this->user     = (string)($cfg['user'] ?? '');
        $this->pass     = (string)($cfg['pass'] ?? '');
        $this->timeout  = (int)($cfg['timeout'] ?? 30);

        if (!$this->baseUrl || !$this->empId || !$this->user || !$this->pass) {
            throw new RuntimeException('Configuración PalJet incompleta. Revisá PALJET_BASE_URL, PALJET_EMP_ID, PALJET_USER, PALJET_PASS.');
        }
    }

    /**
     * GET genérico.
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->request()
            ->get($this->url($path), $query);

        return $this->handleJson($response);
    }

    /**
     * POST genérico.
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        $response = $this->request()
            ->post($this->url($path) . (empty($query) ? '' : '?' . http_build_query($query)), $body);

        return $this->handleJson($response);
    }

    /**
     * Base request con headers y auth.
     */
    private function request()
    {
        return Http::timeout($this->timeout)
            ->acceptJson()
            ->withHeaders([
                'EmpID' => $this->empId,
            ])
            ->withBasicAuth($this->user, $this->pass);
    }

    /**
     * Construye URL absoluta desde path.
     */
    private function url(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $this->baseUrl . $path;
    }

    /**
     * Manejo centralizado de errores + JSON.
     */
    private function handleJson(Response $response): array
    {
        try {
            // Si no es 2xx, tira excepción con info útil.
            $response->throw();
        } catch (RequestException $e) {
            $status = $response->status();
            $body   = $response->body();

            // Mensaje con contexto (sin exponer credenciales)
            throw new RuntimeException("PalJet HTTP {$status}. Body: {$body}");
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException('Respuesta PalJet inválida: no es JSON array/object.');
        }

        return $json;
    }
}
