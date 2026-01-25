<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservas_stock', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_id')
                ->constrained('pedidos')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('sucursal_id')
                ->constrained('sucursales')
                ->restrictOnDelete();

            $table->integer('cantidad');

            $table->enum('estado', ['activa', 'confirmada', 'liberada', 'vencida'])
                ->default('activa');

            $table->timestamp('vence_en')->nullable();

            $table->timestamps();

            $table->index(['producto_id', 'sucursal_id', 'estado']);
            $table->index(['pedido_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_stock');
    }
};
