<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SucursalesSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['nombre' => 'Rawson Centro', 'ciudad' => 'Rawson', 'direccion' => 'Mitre 100', 'telefono' => null, 'activo' => true],
            ['nombre' => 'Rawson Norte', 'ciudad' => 'Rawson', 'direccion' => 'Sarmiento 500', 'telefono' => null, 'activo' => true],
            ['nombre' => 'Playa Unión', 'ciudad' => 'Playa Unión', 'direccion' => 'Av. Costanera 200', 'telefono' => null, 'activo' => true],
        ];

        foreach ($data as $row) {
            DB::table('sucursales')->updateOrInsert(
                ['nombre' => $row['nombre'], 'ciudad' => $row['ciudad']],
                array_merge($row, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
