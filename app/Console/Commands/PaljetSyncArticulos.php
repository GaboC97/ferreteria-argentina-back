<?php

namespace App\Console\Commands;

use App\Integrations\Paljet\Services\PaljetArticuloService;
use App\Models\PaljetArticulo;
use Illuminate\Console\Command;

class PaljetSyncArticulos extends Command
{
    protected $signature = 'paljet:sync-articulos
        {--size=200 : Tamaño de página (PalJet)}
        {--start=0 : Página inicial}
        {--pages=5 : Cantidad de páginas a procesar en esta corrida}';

    protected $description = 'Sincroniza artículos desde PalJet a DB local (paginado).';

    public function handle(PaljetArticuloService $service): int
    {
        $size  = (int)$this->option('size');
        $start = (int)$this->option('start');
        $pages = (int)$this->option('pages');

        $this->info("PalJet Sync Artículos => start={$start} pages={$pages} size={$size}");

        $page = $start;
        $totalUpserts = 0;

        for ($i = 0; $i < $pages; $i++) {
            $data = $service->listar($page, $size);

            $content = $data['content'] ?? [];
            if (!is_array($content)) $content = [];

            foreach ($content as $item) {
                if (!is_array($item)) continue;
                $paljetId = $item['id'] ?? null;
                if (!$paljetId) continue;

                $familia = is_array($item['familia'] ?? null) ? $item['familia'] : null;
                $escala  = is_array($item['escala'] ?? null) ? $item['escala'] : null;

                PaljetArticulo::updateOrCreate(
                    ['paljet_id' => (int)$paljetId],
                    [
                        'codigo'           => $item['codigo'] ?? null,
                        'ean'              => $item['ean'] ?? null,
                        'descripcion'      => $item['descripcion'] ?? null,
                        'desc_cliente'     => $item['desc_cliente'] ?? null,

                        'familia_id'       => $familia['id'] ?? null,
                        'familia_path'     => $familia['familiaPath'] ?? null,

                        'escala_id'        => $escala['id'] ?? null,
                        'escala_nombre'    => $escala['nombre'] ?? null,
                        'escala_abrev'     => $escala['abreviatura'] ?? null,

                        'publica_web'      => (bool)($item['publica_web'] ?? false),
                        'admin_existencia' => (bool)($item['admin_existencia'] ?? false),

                        'impuestos_json'   => $item['impuestos'] ?? null,
                        'raw_json'         => $item,
                    ]
                );

                $totalUpserts++;
            }

            $this->line("Page {$page}: items=" . count($content) . " last=" . (($data['last'] ?? false) ? 'true' : 'false'));

            if (($data['last'] ?? false) === true) {
                $this->info("last=true en page={$page}. Fin.");
                break;
            }

            $page++;
        }

        $this->info("Listo. Upserts: {$totalUpserts}. Próximo start sugerido: {$page}");

        return self::SUCCESS;
    }
}
