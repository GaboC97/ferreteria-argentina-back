<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
public function run(): void
{
    $this->call([
        SucursalesSeeder::class,
        ProductosSeeder::class,
        StockSucursalSeeder::class,
        MediosPagoSeeder::class, // el que ya tenías
    ]);
}


}
