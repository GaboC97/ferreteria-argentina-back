<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contenedor_reservas', function (Blueprint $table) {
            $table->timestamp('devuelta_en')->nullable()->after('updated_at');
            $table->string('motivo_devolucion', 255)->nullable()->after('devuelta_en');
        });
    }

    public function down(): void
    {
        Schema::table('contenedor_reservas', function (Blueprint $table) {
            $table->dropColumn(['devuelta_en', 'motivo_devolucion']);
        });
    }
};
