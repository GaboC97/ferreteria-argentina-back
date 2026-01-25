<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paljet_articulos', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('paljet_id')->unique(); // id de PalJet
            $table->string('codigo')->nullable();
            $table->string('ean')->nullable();

            $table->string('descripcion')->nullable();
            $table->string('desc_cliente')->nullable();

            $table->string('familia_path')->nullable();
            $table->string('familia_id')->nullable(); // viene a veces num / string

            $table->unsignedBigInteger('escala_id')->nullable();
            $table->string('escala_nombre')->nullable();
            $table->string('escala_abrev')->nullable();

            $table->boolean('publica_web')->default(false);
            $table->boolean('admin_existencia')->default(false);

            $table->json('impuestos_json')->nullable();
            $table->json('raw_json')->nullable(); // por si mañana necesitás más campos

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paljet_articulos');
    }
};
