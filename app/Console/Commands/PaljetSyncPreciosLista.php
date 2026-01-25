<?php

namespace App\Console\Commands;

use App\Integrations\Paljet\Services\PaljetPrecioService;
use App\Models\PaljetPrecio;
use Illuminate\Console\Command;

class PaljetSyncPreciosLista extends Command
{
    protected $signature = 'paljet:sync-precios-lista
        {listaId : ID de lista de precios (PalJet)}
        {--size=200}
        {--start=0}
        {--pages=5}';

    protected $description = 'Sincroniza precios de artículos de una lista de precios (HAL).';

    public function handle(PaljetPrecioService $service): int
    {
        $listaId = (int)$this->argument('listaId');
        $size = (int)$this->option('size');
        $start = (int)$this->option('start');
        $pages = (int)$this->option('pages');

        $this->info("Sync precios lista={$listaId} start={$start} pages={$pages} size={$size}");

        $page = $start;
        $upserts = 0;

        for ($i = 0; $i < $pages; $i++) {
            $data = $service->articulosDeLista($listaId, $page, $size);

            $items = $data['_embedded']['listaArticuloResources'] ?? [];
            if (!is_array($items)) $items = [];

            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $artId = $it['articulo']['artId'] ?? null;
                if (!$artId) continue;

                $prVenta = $it['prVenta'] ?? null; // sin IVA
                $prFinal = $it['prFinal'] ?? null; // con IVA

                PaljetPrecio::updateOrCreate(
                    ['lista_id' => $listaId, 'articulo_id' => (int)$artId],
                    [
                        'pr_vta'   => is_null($prVenta) ? null : (float)$prVenta,
                        'pr_final' => is_null($prFinal) ? null : (float)$prFinal,
                        'moneda'   => $it['monedaLista'] ?? null,
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

        $this->info("Listo. Precios upsert: {$upserts}. Próximo start sugerido: {$page}");

        return self::SUCCESS;
    }
}
