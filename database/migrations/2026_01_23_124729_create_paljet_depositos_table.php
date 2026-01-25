<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paljet_depositos', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('paljet_id')->unique(); // idDeposito
            $table->string('nombre');
            $table->boolean('activo')->default(true);

            $table->json('raw_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paljet_depositos');
    }
};
