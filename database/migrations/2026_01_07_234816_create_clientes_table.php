<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();

            // Datos personales y de la cuenta
            $table->string('nombre', 120);
            $table->string('apellido', 120);
            $table->string('email', 160)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // Nullable para permitir creación sin cuenta inicial
            $table->rememberToken();

            // Datos de contacto
            $table->string('telefono', 40)->nullable();

            // Datos Fiscales
            $table->string('dni', 20)->nullable()->unique();
            $table->string('cuit', 20)->nullable()->unique();
            $table->string('condicion_iva', 80)->nullable();
            $table->string('nombre_empresa', 160)->nullable();

            // Dirección de Facturación/Envío principal
            $table->string('direccion_calle', 160)->nullable();
            $table->string('direccion_numero', 20)->nullable();
            $table->string('direccion_piso', 20)->nullable();
            $table->string('direccion_depto', 20)->nullable();
            $table->string('direccion_localidad', 80)->nullable();
            $table->string('direccion_provincia', 80)->nullable();
            $table->string('direccion_codigo_postal', 20)->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
