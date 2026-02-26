<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('dni_contacto', 20)->nullable()->after('telefono_contacto');
            $table->string('cuit_contacto', 20)->nullable()->after('dni_contacto');
            $table->string('condicion_iva_contacto', 80)->nullable()->after('cuit_contacto');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['dni_contacto', 'cuit_contacto', 'condicion_iva_contacto']);
        });
    }
};
