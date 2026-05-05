<?php

namespace App\Repositories;

use App\Models\ProductoFoto;
use Illuminate\Support\Collection;

class ProductoFotoRepository
{
    /**
     * Devuelve un mapa [codigo_producto => tiene_foto] para los códigos dados.
     *
     * @param  array<string>  $codigos
     * @return array<string, bool>
     */
    public function mapByCodigos(array $codigos): array
    {
        if (empty($codigos)) {
            return [];
        }

        return ProductoFoto::whereIn('codigo_producto', $codigos)
            ->pluck('tiene_foto', 'codigo_producto')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    /**
     * Upsert masivo: actualiza o crea registros por codigo_producto.
     *
     * @param  array<array{codigo: string, tiene_foto: bool}>  $items
     */
    public function upsertFotos(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $rows = array_map(fn ($item) => [
            'codigo_producto' => $item['codigo'],
            'tiene_foto'      => (bool) $item['tiene_foto'],
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $items);

        ProductoFoto::upsert(
            $rows,
            ['codigo_producto'],
            ['tiene_foto', 'updated_at']
        );
    }
}
