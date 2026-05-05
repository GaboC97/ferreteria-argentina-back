<?php

namespace App\Services;

use App\Models\CatalogoWeb;
use App\Repositories\ProductoFotoRepository;
use Illuminate\Support\Collection;

class ProductoFotoService
{
    public function __construct(
        private readonly ProductoFotoRepository $repo
    ) {}

    /**
     * Devuelve el listado de productos de Playa Unión (dep_id=8, leído desde catalogo_web)
     * con el campo tiene_foto mergeado desde la tabla local productos_fotos.
     *
     * Filtros opcionales:
     *   - sin_stock: bool  → solo productos con stock <= 0
     *   - sin_foto:  bool  → solo productos sin foto (tiene_foto = false)
     *
     * @param  array{sin_stock?: bool, sin_foto?: bool}  $filtros
     * @return array<int, array{nombre: string, codigo: string, stock: float, tiene_foto: bool}>
     */
    public function getProductosConFotos(array $filtros = []): array
    {
        $query = CatalogoWeb::query()
            ->select(['paljet_art_id', 'codigo', 'descripcion', 'stock'])
            ->whereNotNull('codigo');

        if (!empty($filtros['sin_stock'])) {
            $query->where('stock', '<=', 0);
        }

        /** @var Collection<int, \App\Models\CatalogoWeb> $articulos */
        $articulos = $query->orderBy('descripcion')->get();

        if ($articulos->isEmpty()) {
            return [];
        }

        $codigos   = $articulos->pluck('codigo')->all();
        $fotosMap  = $this->repo->mapByCodigos($codigos);

        $result = $articulos->map(function ($art) use ($fotosMap) {
            return [
                'id'         => $art->paljet_art_id,
                'nombre'     => $art->descripcion ?? '',
                'codigo'     => $art->codigo,
                'stock'      => (float) $art->stock,
                'tiene_foto' => $fotosMap[$art->codigo] ?? false,
            ];
        })->values()->all();

        // Filtros de foto aplicados después del merge (evita N+1 en subquery)
        if (!empty($filtros['sin_foto'])) {
            $result = array_values(array_filter($result, fn ($p) => !$p['tiene_foto']));
        } elseif (!empty($filtros['con_foto'])) {
            $result = array_values(array_filter($result, fn ($p) => $p['tiene_foto']));
        }

        return $result;
    }

    /**
     * Persiste el estado de tiene_foto para una lista de productos.
     * Recibe paljet_art_id, resuelve el codigo desde catalogo_web en una sola query.
     *
     * @param  array<array{id: int, tiene_foto: bool}>  $items
     */
    public function guardarFotos(array $items): void
    {
        $ids = array_column($items, 'id');

        $codigoMap = CatalogoWeb::whereIn('paljet_art_id', $ids)
            ->pluck('codigo', 'paljet_art_id')
            ->all();

        $rows = [];
        foreach ($items as $item) {
            $codigo = $codigoMap[$item['id']] ?? null;
            if ($codigo) {
                $rows[] = ['codigo' => $codigo, 'tiene_foto' => $item['tiene_foto']];
            }
        }

        $this->repo->upsertFotos($rows);
    }
}
