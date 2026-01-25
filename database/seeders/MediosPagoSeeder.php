<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MediosPagoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('medios_pago')->updateOrInsert(
            ['codigo' => 'mercadopago'],
            ['nombre' => 'Mercado Pago', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
