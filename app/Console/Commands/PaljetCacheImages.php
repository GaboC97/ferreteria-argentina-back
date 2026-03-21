<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PaljetService;
use Illuminate\Support\Facades\Cache;

class PaljetCacheImages extends Command
{
    protected $signature = 'paljet:cache-images';
    protected $description = 'Descarga y guarda en disco todas las imágenes del catálogo cacheado';

    public function handle(PaljetService $paljet): int
    {
        $start = microtime(true);

        $this->info('Buscando productos desde caché de catálogo...');

        $productos = collect($paljet->getCachedFullCatalog())
            ->pluck('codigo')
            ->filter()
            ->unique()
            ->values();

        $total = $productos->count();

        $this->info("Productos encontrados: {$total}");

        if ($total === 0) {
            $this->warn('No hay productos en caché. Ejecutá antes: php artisan paljet:refresh-catalog-cache');
            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $procesadas = 0;
        $existentes = 0;
        $sinImagen  = 0;
        $errores    = 0;

        $erroresDetalle = [];

        // Procesar en bloques para evitar saturar el ERP
        $productos->chunk(10)->each(function ($chunk) use (
            $paljet,
            &$procesadas,
            &$existentes,
            &$sinImagen,
            &$errores,
            &$erroresDetalle,
            $bar
        ) {

            foreach ($chunk as $codigo) {

                $path = public_path("productos/{$codigo}.jpg");

                // Si ya existe la imagen
                if (file_exists($path)) {
                    $existentes++;
                    $bar->advance();
                    continue;
                }

                // Si ya sabemos que no tiene imagen (cache)
                if (Cache::has("sin_imagen_{$codigo}")) {
                    $sinImagen++;
                    $bar->advance();
                    continue;
                }

                $result = $paljet->getImagenArticulo($codigo);

                if (isset($result['error'])) {

                    if (($result['status'] ?? null) === 404) {

                        $sinImagen++;

                        // Guardar en cache para no volver a pedirla
                        Cache::put("sin_imagen_{$codigo}", true, now()->addDays(7));

                    } else {

                        $errores++;

                        $erroresDetalle[] = [
                            'codigo' => $codigo,
                            'error'  => $result['error'] ?? 'desconocido'
                        ];
                    }

                } else {

                    $procesadas++;

                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $time = round(microtime(true) - $start, 2);

        $this->info("========== RESUMEN ==========");
        $this->line("Total productos: {$total}");
        $this->line("Descargadas nuevas: {$procesadas}");
        $this->line("Ya existentes: {$existentes}");
        $this->line("Sin imagen: {$sinImagen}");
        $this->line("Errores: {$errores}");
        $this->line("Tiempo total: {$time} segundos");

        if ($errores > 0) {

            $this->warn("Errores detectados:");

            foreach ($erroresDetalle as $error) {

                $this->line(
                    "Código: {$error['codigo']} | Error: {$error['error']}"
                );
            }
        }

        $this->info("Proceso terminado.");

        return self::SUCCESS;
    }
}