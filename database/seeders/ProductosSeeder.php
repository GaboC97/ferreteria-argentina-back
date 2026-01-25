<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductosSeeder extends Seeder
{
    public function run(): void
    {
        $productos = [
            ['nombre' => 'Martillo 16oz', 'precio' => 12000, 'marca' => 'Genérica', 'unidad' => 'unidad'],
            ['nombre' => 'Destornillador Philips', 'precio' => 6500, 'marca' => 'Genérica', 'unidad' => 'unidad'],
            ['nombre' => 'Taladro 750W', 'precio' => 85000, 'marca' => 'Genérica', 'unidad' => 'unidad'],
            ['nombre' => 'Caja de tornillos x100', 'precio' => 9000, 'marca' => 'Genérica', 'unidad' => 'caja'],
            ['nombre' => 'Cinta aisladora', 'precio' => 1800, 'marca' => 'Genérica', 'unidad' => 'unidad'],
        ];

        foreach ($productos as $p) {
            $slug = Str::slug($p['nombre']);

            DB::table('productos')->updateOrInsert(
                ['slug' => $slug],
                [
                    'categoria_id' => null,
                    'nombre' => $p['nombre'],
                    'slug' => $slug,
                    'codigo' => null,
                    'descripcion' => null,
                    'precio' => $p['precio'],
                    'marca' => $p['marca'],
                    'unidad' => $p['unidad'],
                    'activo' => true,
                    'destacado' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
