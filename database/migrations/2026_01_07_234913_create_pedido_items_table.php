<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedido_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_id')
                ->constrained('pedidos')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            // Snapshot del producto al momento de comprar
            $table->string('nombre_producto', 180);
            $table->decimal('precio_unitario', 12, 2);
            $table->integer('cantidad');

            $table->decimal('subtotal', 12, 2);

            $table->timestamps();

            $table->index(['pedido_id']);
            $table->index(['producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_items');
    }
};
