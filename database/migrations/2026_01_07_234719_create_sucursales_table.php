<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);                // "Rawson Centro", "Rawson Norte", "Playa Unión"
            $table->string('ciudad', 80);                 // "Rawson", "Playa Unión"
            $table->string('direccion', 160);
            $table->string('telefono', 40)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['nombre', 'ciudad']); // evita duplicados obvios
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
