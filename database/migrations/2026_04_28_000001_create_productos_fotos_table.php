<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_fotos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_producto', 100)->unique();
            $table->boolean('tiene_foto')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_fotos');
    }
};
