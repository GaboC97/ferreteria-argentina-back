<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paljet_precios', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('lista_id');     // paljet_listas_precios.paljet_id (no FK estricta para evitar lío)
            $table->unsignedBigInteger('articulo_id');  // paljet_articulos.paljet_id

            // valores típicos (pueden variar según response real; guardamos raw_json por seguridad)
            $table->decimal('pr_vta', 18, 2)->nullable();     // sin IVA
            $table->decimal('pr_final', 18, 2)->nullable();   // con IVA

            $table->string('moneda')->nullable();
            $table->json('raw_json')->nullable();

            $table->timestamps();

            $table->unique(['lista_id', 'articulo_id']);
            $table->index(['articulo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paljet_precios');
    }
};
