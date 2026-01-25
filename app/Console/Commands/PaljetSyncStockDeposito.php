<?php

namespace App\Console\Commands;

use App\Integrations\Paljet\Services\PaljetStockService;
use App\Models\PaljetStock;
use Illuminate\Console\Command;

class PaljetSyncStockDeposito extends Command
{
    protected $signature = 'paljet:sync-stock-deposito
        {depId : ID de depósito (PalJet)}
        {--size=200}
        {--start=0}
        {--pages=5}';

    protected $description = 'Sincroniza stock de artículos para un depósito.';

    public function handle(PaljetStockService $service): int
    {
        $depId  = (int)$this->argument('depId');
        $size   = (int)$this->option('size');
        $start  = (int)$this->option('start');
        $pages  = (int)$this->option('pages');

        $this->info("Sync stock dep={$depId} start={$start} pages={$pages} size={$size}");

        $page = $start;
        $upserts = 0;

        for ($i=0; $i<$pages; $i++) {
            $data = $service->stockDeposito($depId, $page, $size);

            $items = $data['content'] ?? [];
            if (!is_array($items)) $items = [];

            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $artId = $it['articulo']['id'] ?? null;
                if (!$artId) continue;

                PaljetStock::updateOrCreate(
                    ['deposito_id' => $depId, 'articulo_id' => (int)$artId],
                    [
                        'existencia'   => (float)($it['existencia'] ?? 0),
                        'disponible'   => (float)($it['disponible'] ?? 0),
                        'comprometido' => (float)($it['comprometido'] ?? 0),
                        'a_recibir'    => (float)($it['a_recibir'] ?? 0),
                        'stk_min'      => (float)($it['stk_min'] ?? 0),
                        'raw_json'     => $it,
                    ]
                );

                $upserts++;
            }

            $this->line("Page {$page}: items=" . count($items) . " last=" . (($data['last'] ?? false) ? 'true' : 'false'));

            if (($data['last'] ?? false) === true) break;

            $page++;
        }

        $this->info("Listo. Stock upsert: {$upserts}. Próximo start sugerido: {$page}");
        return self::SUCCESS;
    }
}
