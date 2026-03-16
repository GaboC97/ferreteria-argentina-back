<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_historicos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_order_id')->unique()->comment('ID original del pedido en WooCommerce');
            $table->dateTime('fecha');
            $table->decimal('total', 12, 2);
            $table->string('estado', 50);       // completado, cancelado, pendiente, en_espera
            $table->string('estado_woo', 60);   // estado original de WC, ej: wc-completed
            $table->unsignedBigInteger('woo_customer_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_historicos');
    }
};
