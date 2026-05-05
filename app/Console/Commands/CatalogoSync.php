<?php

namespace App\Console\Commands;

use App\Models\CatalogoWeb;
use App\Models\PaljetArticuloOculto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CatalogoSync extends Command
{
    protected $signature   = 'catalogo:sync {--force : Forzar sync aunque sea reciente}';
    protected $description = 'Sincroniza el catálogo completo desde Paljet a la tabla catalogo_web';

    private string $baseUrl;
    private string $user;
    private string $pass;
    private int    $empId;
    private int    $depId;
    private int    $timeout;

    public function handle(): int
    {
        $this->baseUrl = rtrim(config('services.paljet.base_url'), '/');
        $this->user    = config('services.paljet.user');
        $this->pass    = config('services.paljet.pass');
        $this->empId   = (int) config('services.paljet.emp_id', 1);
        $this->depId   = (int) config('services.paljet.dep_id', 8);
        $this->timeout = (int) config('services.paljet.timeout', 60);

        set_time_limit(300);

        $this->info('Iniciando sync del catálogo...');

        // 1. Descargar artículos
        $articulos = $this->fetchArticulos();
        $this->line("  Artículos descargados: " . count($articulos));

        if (empty($articulos)) {
            $this->error('No se obtuvieron artículos. Abortando.');
            return self::FAILURE;
        }

        // 2. Mapa de stock
        $stockMap = $this->fetchStockMap();
        $this->line("  Entradas de stock obtenidas: " . count($stockMap));

        // 3. IDs ocultos
        $ocultosSet = PaljetArticuloOculto::pluck('paljet_art_id')->flip()->all();

        // 4. Umbral de stock alerta y art_id del contenedor
        $umbral          = (int) config('services.paljet.stock_alerta', 3);
        $contenedorArtId = (int) config('services.paljet.contenedor_art_id', 12441);

        // 5. Procesar y hacer upsert en lotes
        $upserts  = 0;
        $omitidos = 0;
        $batch    = [];

        foreach ($articulos as $art) {
            $artId = (int) ($art['id'] ?? 0);

            if (!$artId || isset($ocultosSet[$artId])) {
                $omitidos++;
                continue;
            }

            // Precio desde listas (pr_final = con IVA, pr_venta = sin IVA)
            $precio     = 0.0;
            $precioNeto = 0.0;
            foreach ($art['listas'] ?? [] as $lista) {
                if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                    $precio     = (float) $lista['pr_final'];
                    $precioNeto = (float) ($lista['pr_venta'] ?? 0);
                    break;
                }
            }
            if ($precio === 0.0 && isset($art['pr_final'])) {
                $precio = (float) $art['pr_final'];
            }

            // Solo artículos con precio
            if ($precio === 0.0) {
                $omitidos++;
                continue;
            }

            // Alícuota IVA del primer impuesto (en general IVGR)
            $alicuota = (float) ($art['impuestos'][0]['impuesto']['alicuota'] ?? 0);

            // Stock
            // El contenedor se trata como sin restricción de stock (admin_existencia=false)
            // para que siempre figure como disponible independientemente del stock en dep_id=8.
            $adminExistencia = $artId === $contenedorArtId
                ? false
                : (bool) ($art['admin_existencia'] ?? false);
            $stock           = (float) ($stockMap[$artId] ?? 0);
            $ultimasUnidades = $adminExistencia && $stock > 0 && $stock <= $umbral;

            // Imagen
            $codigo    = $art['codigo'] ?? null;
            $imagenUrl = $codigo ? url("/api/catalogo/{$codigo}/imagen") : null;

            // Marca / Familia / Categoría
            $marca    = is_array($art['marca']    ?? null) ? $art['marca']    : null;
            $familia  = is_array($art['familia']  ?? null) ? $art['familia']  : null;
            $categoria = is_array($art['categoria'] ?? null) ? $art['categoria'] : null;

            // Slim de listas
            $listas = [];
            foreach ($art['listas'] ?? [] as $l) {
                $listas[] = array_intersect_key($l, array_flip([
                    'lista', 'lista_id', 'lista_nombre', 'pr_final', 'pr_vta', 'nombre',
                ]));
            }

            $batch[] = [
                'paljet_art_id'   => $artId,
                'codigo'          => $codigo,
                'ean'             => $art['ean'] ?? null,
                'descripcion'     => $art['descripcion'] ?? null,
                'desc_cliente'    => $art['desc_cliente'] ?? null,
                'desc_mod_med'    => $art['desc_mod_med'] ?? null,
                'marca_id'        => $marca ? (int) ($marca['id'] ?? 0) : null,
                'marca_nombre'    => $marca ? ($marca['nombre'] ?? null) : null,
                'familia_id'      => $familia ? (int) ($familia['id'] ?? 0) : null,
                'familia_nombre'  => $familia ? ($familia['nombre'] ?? null) : null,
                'categoria_id'    => $categoria ? (int) ($categoria['id'] ?? 0) : null,
                'categoria_nombre' => $categoria ? ($categoria['nombre'] ?? null) : null,
                'precio'          => $precio,
                'precio_neto'     => $precioNeto,
                'iva_alicuota'    => $alicuota,
                'admin_existencia' => $adminExistencia,
                'stock'           => $stock,
                'ultimas_unidades' => $ultimasUnidades,
                'imagen_url'      => $imagenUrl,
                'listas_json'     => json_encode($listas),
                'raw_json'        => json_encode($art),
                'synced_at'       => now()->toDateTimeString(),
                'first_seen_at'   => now()->toDateTimeString(),
            ];

            // Flush en lotes de 200.
            // first_seen_at se setea solo en INSERT y se preserva en UPDATE
            // (no se incluye en la lista de columnas a actualizar).
            if (count($batch) >= 200) {
                $updateCols = array_values(array_diff(array_keys($batch[0]), ['first_seen_at']));
                CatalogoWeb::upsert($batch, ['paljet_art_id'], $updateCols);
                $upserts += count($batch);
                $batch = [];
            }
        }

        // Último lote
        if (!empty($batch)) {
            $updateCols = array_values(array_diff(array_keys($batch[0]), ['first_seen_at']));
            CatalogoWeb::upsert($batch, ['paljet_art_id'], $updateCols);
            $upserts += count($batch);
        }

        // 6. Eliminar artículos que ya no están en el catálogo activo
        // El contenedor siempre se preserva aunque Paljet no lo devuelva por falta de stock.
        $activePaljetIds = collect($articulos)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->filter()
            ->all();

        if (!empty($activePaljetIds)) {
            $deleted = CatalogoWeb::whereNotIn('paljet_art_id', $activePaljetIds)
                ->where('paljet_art_id', '<>', $contenedorArtId)
                ->delete();
            if ($deleted > 0) {
                $this->line("  Artículos eliminados (ya no en ERP): {$deleted}");
            }
        }

        // 7. Sincronizar precio del contenedor desde Paljet → productos local
        $this->sincronizarPrecioContenedor($articulos);

        // 8. Actualizar ventas_count desde pedido_items (pedidos aprobados)
        $this->actualizarVentasCount();

        // 9. Regenerar caché del árbol de categorías durante el sync (no durante el request del usuario)
        $this->regenerarCacheCategoriasWeb($upserts > 0 ? $articulos : []);

        $this->info("Sync completo. Upserts: {$upserts} | Omitidos: {$omitidos}");
        Log::info('CatalogoSync completado', ['upserts' => $upserts, 'omitidos' => $omitidos]);

        return self::SUCCESS;
    }

    private function client()
    {
        return Http::withBasicAuth($this->user, $this->pass)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['EmpID' => $this->empId])
            ->timeout($this->timeout);
    }

    private function fetchArticulos(): array
    {
        $pageSize = 1000;
        $all      = [];
        $page     = 0;

        do {
            $response = $this->client()->get("{$this->baseUrl}/articulos", [
                'dep_id'       => $this->depId,
                'solo_activos' => 'true',
                'include'      => 'listas',
                'size'         => $pageSize,
                'page'         => $page,
            ]);

            if (!$response->successful()) {
                Log::error('CatalogoSync - Error al paginar artículos', [
                    'page'   => $page,
                    'status' => $response->status(),
                ]);
                break;
            }

            $data       = $response->json();
            $all        = array_merge($all, $data['content'] ?? []);
            $totalPages = (int) ($data['totalPages'] ?? 1);
            $page++;

            $this->line("  Artículos página {$page}/{$totalPages}...");
        } while ($page < $totalPages);

        return $all;
    }

    private function sincronizarPrecioContenedor(array $articulos): void
    {
        $contenedorArtId = (int) config('services.paljet.contenedor_art_id', 12441);

        foreach ($articulos as $art) {
            if ((int) ($art['id'] ?? 0) !== $contenedorArtId) {
                continue;
            }

            $precio = 0.0;
            foreach ($art['listas'] ?? [] as $lista) {
                if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                    $precio = (float) $lista['pr_final'];
                    break;
                }
            }
            if ($precio === 0.0 && isset($art['pr_final'])) {
                $precio = (float) $art['pr_final'];
            }

            if ($precio > 0) {
                $affected = \Illuminate\Support\Facades\DB::table('productos')
                    ->where('es_contenedor', true)
                    ->update(['precio' => $precio, 'updated_at' => now()]);

                $this->line("  Precio contenedor sincronizado desde Paljet: \${$precio} ({$affected} producto/s).");
            } else {
                $this->warn("  Contenedor art_id={$contenedorArtId} encontrado pero sin precio en listas.");
            }

            return;
        }

        $this->line("  Contenedor art_id={$contenedorArtId} no encontrado en el catálogo Paljet (sin stock, ok).");
    }

    private function actualizarVentasCount(): void
    {
        try {
            // Sumar cantidad vendida por paljet_art_id en pedidos aprobados
            $ventas = \Illuminate\Support\Facades\DB::table('pedido_items')
                ->join('pedidos', 'pedido_items.pedido_id', '=', 'pedidos.id')
                ->whereIn('pedidos.estado', ['aprobado', 'completado'])
                ->whereNotNull('pedido_items.paljet_art_id')
                ->selectRaw('pedido_items.paljet_art_id, SUM(pedido_items.cantidad) as total')
                ->groupBy('pedido_items.paljet_art_id')
                ->pluck('total', 'paljet_art_id');

            if ($ventas->isEmpty()) {
                $this->line("  ventas_count: sin datos de pedidos aprobados aún.");
                return;
            }

            // Actualizar en lotes
            foreach ($ventas->chunk(200) as $chunk) {
                foreach ($chunk as $artId => $total) {
                    CatalogoWeb::where('paljet_art_id', $artId)
                        ->update(['ventas_count' => (int) $total]);
                }
            }

            $this->line("  ventas_count actualizado para {$ventas->count()} artículos.");
        } catch (\Throwable $e) {
            $this->warn("  Error actualizando ventas_count: " . $e->getMessage());
        }
    }

    private function regenerarCacheCategoriasWeb(array $articulos): void
    {
        try {
            // IDs de categorías con artículos activos en la tabla local
            $activeCatIds = CatalogoWeb::where('precio', '>', 0)
                ->whereNotNull('categoria_id')
                ->pluck('categoria_id')
                ->unique()
                ->flip()
                ->all();

            // Árbol completo desde el ERP (una sola vez, aquí en el sync, no en el request del usuario)
            $response = $this->client()->get("{$this->baseUrl}/categorias");

            if (!$response->successful()) {
                $this->warn("  No se pudo obtener el árbol de categorías del ERP.");
                return;
            }

            $tree      = $response->json();
            $rootHijos = (is_array($tree) && isset($tree[0]['hijos'])) ? $tree[0]['hijos'] : [];
            $podado    = $this->pruneTree($rootHijos, $activeCatIds);

            $ttl      = (int) config('services.paljet.cache_ttl', 600);
            Cache::put("paljet_categorias_{$this->depId}", $podado, $ttl);
            Cache::put("paljet_categorias_tree_{$this->empId}", $tree, $ttl);

            $this->line("  Caché de categorías regenerado.");
        } catch (\Throwable $e) {
            $this->warn("  Error regenerando caché de categorías: " . $e->getMessage());
        }
    }

    private function pruneTree(array $nodes, array $activeCatIds): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $id    = $node['art_cat_id'];
            $hijos = $this->pruneTree($node['hijos'] ?? [], $activeCatIds);

            if (isset($activeCatIds[$id]) || count($hijos) > 0) {
                $result[] = [
                    'id'     => $id,
                    'nombre' => $node['nombre'],
                    'hijos'  => $hijos,
                ];
            }
        }
        return $result;
    }

    private function fetchStockMap(): array
    {
        $map      = [];
        $page     = 0;
        $pageSize = 1000;

        do {
            $response = $this->client()->get("{$this->baseUrl}/stock", [
                'depositos' => $this->depId,
                'size'      => $pageSize,
                'page'      => $page,
            ]);

            if (!$response->successful()) {
                break;
            }

            $data       = $response->json();
            $entries    = $data['content'] ?? [];
            $totalPages = (int) ($data['totalPages'] ?? 1);

            foreach ($entries as $entry) {
                $artId = $entry['articulo']['art_id'] ?? null;
                if ($artId !== null) {
                    $map[(int) $artId] = (float) ($entry['disponible'] ?? 0);
                }
            }

            $page++;
        } while ($page < $totalPages);

        return $map;
    }
}
