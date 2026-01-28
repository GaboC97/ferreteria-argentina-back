<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'), // Contraseña por defecto para testing
            'remember_token' => Str::random(10),
            'telefono' => fake()->phoneNumber(),
            'dni' => fake()->unique()->numerify('########'),
            'cuit' => null,
            'condicion_iva' => fake()->randomElement(['Consumidor Final', 'Monotributista']),
            'nombre_empresa' => null,
            'direccion_calle' => fake()->streetName(),
            'direccion_numero' => fake()->buildingNumber(),
            'direccion_localidad' => fake()->city(),
            'direccion_provincia' => fake()->state(),
            'direccion_codigo_postal' => fake()->postcode(),
            'activo' => true,
        ];
    }

    /**
     * Define un estado para crear un cliente de tipo empresa/Responsable Inscripto.
     */
    public function comoEmpresa(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'cuit' => fake()->unique()->numerify('20-########-#'),
                'dni' => null,
                'condicion_iva' => 'Responsable Inscripto',
                'nombre_empresa' => fake()->company(),
            ];
        });
    }
}
