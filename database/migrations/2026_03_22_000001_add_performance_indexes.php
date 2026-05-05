<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->index('created_at', 'idx_pedidos_created_at');
            $table->index(['cliente_id', 'estado'], 'idx_pedidos_cliente_estado');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->index('created_at', 'idx_clientes_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex('idx_pedidos_created_at');
            $table->dropIndex('idx_pedidos_cliente_estado');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex('idx_clientes_created_at');
        });
    }
};
