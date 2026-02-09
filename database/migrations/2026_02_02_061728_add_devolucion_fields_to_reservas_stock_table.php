<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservas_stock', function (Blueprint $table) {
            // si estado hoy es string, no tocamos enum, solo lo usamos con valores nuevos
            $table->timestamp('devuelta_en')->nullable()->after('vence_en');
        });
    }

    public function down(): void
    {
        Schema::table('reservas_stock', function (Blueprint $table) {
            $table->dropColumn('devuelta_en');
        });
    }
};
