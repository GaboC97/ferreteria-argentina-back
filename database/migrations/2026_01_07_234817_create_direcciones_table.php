<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('direcciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnDelete();

            $table->string('alias', 80)->nullable(); // "Casa", "Trabajo" (opcional)
            $table->string('nombre_recibe', 160)->nullable();
            $table->string('telefono_recibe', 40)->nullable();

            $table->string('calle', 160);
            $table->string('numero', 20);
            $table->string('piso', 20)->nullable();
            $table->string('depto', 20)->nullable();

            $table->string('ciudad', 80);
            $table->string('provincia', 80);
            $table->string('codigo_postal', 20)->nullable();

            $table->string('referencias', 255)->nullable();

            $table->boolean('es_principal')->default(false);

            $table->timestamps();

            $table->index(['cliente_id', 'es_principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcciones');
    }
};
