<?php

namespace App\Integrations\Paljet\Services;

use App\Integrations\Paljet\PaljetClient;

class PaljetStockService
{
    public function __construct(private PaljetClient $client) {}

    public function depositos(): array
    {
        return $this->client->get('/depositos');
    }

    public function stockDeposito(int $depId, int $page = 0, int $size = 200): array
    {
        return $this->client->get("/depositos/{$depId}/stock", [
            'page' => $page,
            'size' => $size,
        ]);
    }
}
