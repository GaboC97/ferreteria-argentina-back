<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedBigInteger('paljet_art_id')->nullable()->after('id')
                ->comment('ID del artículo equivalente en Paljet (para facturación)');
        });

        // Mapear los productos de contenedor al artículo 12441 de Paljet:
        // "PRESTAC. DE SERVICIO | CONTENEDOR EN RAWSON | DISPONIBLE HASTA 3 DIAS"
        DB::table('productos')
            ->whereIn('id', [1, 512])
            ->update(['paljet_art_id' => 12441]);
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('paljet_art_id');
        });
    }
};
