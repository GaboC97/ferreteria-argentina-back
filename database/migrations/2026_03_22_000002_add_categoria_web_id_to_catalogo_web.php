<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->unsignedBigInteger('categoria_web_id')->nullable()->index()->after('categoria_nombre');
            $table->foreign('categoria_web_id')->references('id')->on('categorias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->dropForeign(['categoria_web_id']);
            $table->dropColumn('categoria_web_id');
        });
    }
};
