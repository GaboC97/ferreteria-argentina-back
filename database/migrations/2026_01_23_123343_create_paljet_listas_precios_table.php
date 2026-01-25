<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paljet_listas_precios', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('paljet_id')->unique(); // id lista en PalJet
            $table->string('nombre')->nullable();
            $table->boolean('activa')->default(true);

            $table->json('raw_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paljet_listas_precios');
    }
};
