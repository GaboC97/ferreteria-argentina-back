<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paljet_stock', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('deposito_id');        // paljet_depositos.paljet_id
            $table->unsignedBigInteger('articulo_id');     // paljet_articulos.paljet_id

            $table->decimal('existencia', 18, 2)->default(0);
            $table->decimal('disponible', 18, 2)->default(0);
            $table->decimal('comprometido', 18, 2)->default(0);
            $table->decimal('a_recibir', 18, 2)->default(0);
            $table->decimal('stk_min', 18, 2)->default(0);

            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['deposito_id','articulo_id']);
            $table->index(['articulo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paljet_stock');
    }
};
