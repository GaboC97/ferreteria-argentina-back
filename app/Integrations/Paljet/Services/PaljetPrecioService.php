<?php

namespace App\Integrations\Paljet\Services;

use App\Integrations\Paljet\PaljetClient;

class PaljetPrecioService
{
    public function __construct(private PaljetClient $client) {}

    public function listas(int $page = 0, int $size = 50): array
    {
        return $this->client->get('/listasprecios', [
            'page' => $page,
            'size' => $size,
        ]);
    }

    public function articulosDeLista(int $listaId, int $page = 0, int $size = 200): array
    {
        return $this->client->get("/listasprecios/{$listaId}/articulos", [
            'page' => $page,
            'size' => $size,
        ]);
    }
}
