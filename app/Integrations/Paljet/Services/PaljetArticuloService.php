<?php

namespace App\Integrations\Paljet\Services;

use App\Integrations\Paljet\PaljetClient;

class PaljetArticuloService
{
    public function __construct(private PaljetClient $client) {}

    public function listar(int $page = 0, int $size = 200): array
    {
        return $this->client->get('/articulos', [
            'size' => $size,
            'page' => $page,
        ]);
    }
}
