<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalogo_web', function (Blueprint $table) {
            $table->unsignedBigInteger('paljet_art_id')->primary();

            $table->string('codigo', 100)->nullable()->index();
            $table->string('ean', 100)->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->string('desc_cliente', 500)->nullable();
            $table->string('desc_mod_med', 500)->nullable();

            // Marca
            $table->unsignedInteger('marca_id')->nullable()->index();
            $table->string('marca_nombre', 200)->nullable();

            // Familia
            $table->unsignedInteger('familia_id')->nullable();
            $table->string('familia_nombre', 200)->nullable();

            // Categoría
            $table->unsignedInteger('categoria_id')->nullable()->index();
            $table->string('categoria_nombre', 200)->nullable();

            // Precio (lista principal)
            $table->decimal('precio', 14, 2)->default(0)->index();

            // Stock
            $table->boolean('admin_existencia')->default(false);
            $table->decimal('stock', 10, 2)->default(0);
            $table->boolean('ultimas_unidades')->default(false);

            // Imagen
            $table->string('imagen_url', 500)->nullable();

            // Datos extra en JSON (listas de precios completas, etc.)
            $table->json('listas_json')->nullable();
            $table->json('raw_json')->nullable();

            $table->timestamp('synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogo_web');
    }
};
