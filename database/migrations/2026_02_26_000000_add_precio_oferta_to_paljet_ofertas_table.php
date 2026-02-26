<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paljet_ofertas', function (Blueprint $table) {
            $table->decimal('precio_oferta', 10, 2)->nullable()->after('paljet_art_id');
        });
    }

    public function down(): void
    {
        Schema::table('paljet_ofertas', function (Blueprint $table) {
            $table->dropColumn('precio_oferta');
        });
    }
};
