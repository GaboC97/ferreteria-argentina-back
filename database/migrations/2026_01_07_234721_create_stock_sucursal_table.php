<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_sucursal', function (Blueprint $table) {
            $table->id();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnDelete();

            $table->foreignId('sucursal_id')
                ->constrained('sucursales')
                ->cascadeOnDelete();

            $table->integer('cantidad')->default(0); // >= 0 (lo validamos en app)

            $table->timestamps();

            // Clave: un stock por producto+sucursal
            $table->unique(['producto_id', 'sucursal_id']);

            $table->index(['sucursal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_sucursal');
    }
};
