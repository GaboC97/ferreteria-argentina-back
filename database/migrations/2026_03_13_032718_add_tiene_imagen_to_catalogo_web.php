<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            // null = no verificado, true = tiene imagen, false = sin imagen
            $table->boolean('tiene_imagen')->nullable()->default(null)->after('imagen_url');
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->dropColumn('tiene_imagen');
        });
    }
};
