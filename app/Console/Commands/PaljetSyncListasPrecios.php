<?php

namespace App\Console\Commands;

use App\Integrations\Paljet\Services\PaljetPrecioService;
use App\Models\PaljetListaPrecio;
use Illuminate\Console\Command;

class PaljetSyncListasPrecios extends Command
{
    protected $signature = 'paljet:sync-listas-precios {--size=50} {--start=0} {--pages=5}';
    protected $description = 'Sincroniza listas de precios desde PalJet (HAL) a DB local.';

    public function handle(PaljetPrecioService $service): int
    {
        $size  = (int)$this->option('size');
        $start = (int)$this->option('start');
        $pages = (int)$this->option('pages');

        $page = $start;
        $upserts = 0;

        $this->info("Sync listas-precios: start={$start} pages={$pages} size={$size}");

        for ($i = 0; $i < $pages; $i++) {
            $data = $service->listas($page, $size);

            $items = $data['_embedded']['listaPrecioResources'] ?? [];
            if (!is_array($items)) $items = [];

            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $listaId = $it['listaId'] ?? null;
                if ($listaId === null) continue;

                PaljetListaPrecio::updateOrCreate(
                    ['paljet_id' => (int)$listaId],
                    [
                        'nombre' => $it['nombre'] ?? null,
                        'activa' => (bool)($it['activa'] ?? true),
                        'raw_json' => $it,
                    ]
                );

                $upserts++;
            }

            $totalPages = $data['page']['totalPages'] ?? null;
            $this->line("Page {$page}: items=" . count($items) . " totalPages=" . ($totalPages ?? '?'));

            $page++;

            if (!is_null($totalPages) && $page >= (int)$totalPages) {
                $this->info("Llegó al final (page >= totalPages).");
                break;
            }
        }

        $this->info("Listo. Listas upsert: {$upserts}. Próximo start sugerido: {$page}");

        return self::SUCCESS;
    }
}
