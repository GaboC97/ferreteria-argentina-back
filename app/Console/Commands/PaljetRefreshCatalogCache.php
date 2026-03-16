<?php

namespace App\Console\Commands;

use App\Services\PaljetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PaljetRefreshCatalogCache extends Command
{
    protected $signature   = 'paljet:refresh-catalog-cache';
    protected $description = 'Descarga el catálogo completo de Paljet y actualiza la caché';

    public function handle(PaljetService $paljet): int
    {
        $this->info('Actualizando caché del catálogo Paljet...');

        $catalog = $paljet->warmupCatalogCache();

        $this->info('Listo. Artículos publicables cacheados: ' . count($catalog));

        $this->info('Actualizando caché de imágenes...');
        Artisan::call('paljet:cache-images');

        return self::SUCCESS;
    }
}