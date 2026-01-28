<?php

namespace Database\Seeders;

use App\Models\Cliente;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClienteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear un cliente específico para pruebas de login
        Cliente::factory()->create([
            'nombre' => 'Cliente',
            'apellido' => 'De Prueba',
            'email' => 'cliente@prueba.com',
            'password' => Hash::make('12345678'),
            'condicion_iva' => 'Consumidor Final',
        ]);

        // Crear 10 clientes de prueba (Particulares)
        Cliente::factory(10)->create();

        // Crear 3 clientes de prueba (Empresas)
        Cliente::factory(3)->comoEmpresa()->create();
    }
}
