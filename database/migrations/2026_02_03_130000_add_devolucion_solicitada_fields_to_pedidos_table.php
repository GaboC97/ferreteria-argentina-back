<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('estado_antes_devolucion', 40)->nullable()->after('estado');
            $table->string('motivo_devolucion', 255)->nullable()->after('nota_interna');
            $table->timestamp('devolucion_solicitada_en')->nullable()->after('motivo_devolucion');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['estado_antes_devolucion', 'motivo_devolucion', 'devolucion_solicitada_en']);
        });
    }
};
