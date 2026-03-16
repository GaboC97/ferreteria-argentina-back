<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paljet_articulos_ocultos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paljet_art_id')->unique();
            $table->string('motivo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paljet_articulos_ocultos');
    }
};
