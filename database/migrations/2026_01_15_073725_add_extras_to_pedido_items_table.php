<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pedido_items', function (Blueprint $table) {
            // JSON para guardar metadata del item (contenedor u otros servicios futuros)
            $table->json('extras')->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->dropColumn('extras');
        });
    }
};
