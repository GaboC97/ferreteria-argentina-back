<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pedido_items: agregar paljet_art_id y hacer producto_id nullable
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->unsignedBigInteger('paljet_art_id')->nullable()->after('producto_id');
            $table->dropForeign(['producto_id']);
            $table->unsignedBigInteger('producto_id')->nullable()->change();
        });

        // pedidos: guardar el ID del pedido creado en Paljet
        Schema::table('pedidos', function (Blueprint $table) {
            $table->unsignedBigInteger('paljet_pedido_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->dropColumn('paljet_art_id');
            $table->unsignedBigInteger('producto_id')->nullable(false)->change();
            $table->foreign('producto_id')->references('id')->on('productos')->restrictOnDelete();
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('paljet_pedido_id');
        });
    }
};
