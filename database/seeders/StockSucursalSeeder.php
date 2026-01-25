<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StockSucursalSeeder extends Seeder
{
    public function run(): void
    {
        $sucursales = DB::table('sucursales')->pluck('id')->all();
        $productos = DB::table('productos')->pluck('id')->all();

        foreach ($sucursales as $sid) {
            foreach ($productos as $pid) {
                DB::table('stock_sucursal')->updateOrInsert(
                    ['sucursal_id' => $sid, 'producto_id' => $pid],
                    [
                        'cantidad' => 20, // stock inicial prueba
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
