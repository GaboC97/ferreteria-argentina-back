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
        $inicio = microtime(true);

        $this->info('========================================');
        $this->info('INICIANDO REFRESH DE CATALOGO PALJET');
        $this->info('========================================');

        $this->info('Descargando catálogo desde ERP...');

        try {

            $catalog = $paljet->warmupCatalogCache();

        } catch (\Throwable $e) {

            $this->error('Error al actualizar catálogo: ' . $e->getMessage());

            return self::FAILURE;
        }

        $total = count($catalog);

        $this->info("Catálogo cacheado correctamente.");
        $this->line("Artículos publicables: {$total}");

        if ($total === 0) {
            $this->warn('El catálogo está vacío. Verificá conexión con Paljet.');
        }

        $this->newLine();

        $this->info('========================================');
        $this->info('INICIANDO CACHE DE IMAGENES');
        $this->info('========================================');

        $exitCode = Artisan::call('paljet:cache-images');

        $this->line(Artisan::output());

        if ($exitCode !== 0) {
            $this->error('El comando de cache de imágenes terminó con errores.');
        }

        $tiempo = round(microtime(true) - $inicio, 2);
        $memoria = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $this->newLine();

        $this->info('========================================');
        $this->info('RESUMEN');
        $this->info('========================================');

        $this->line("Productos en catálogo: {$total}");
        $this->line("Tiempo total ejecución: {$tiempo} segundos");
        $this->line("Memoria máxima usada: {$memoria} MB");

        $this->info('Proceso finalizado correctamente.');

        return self::SUCCESS;
    }
}