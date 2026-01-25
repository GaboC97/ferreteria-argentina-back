<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('producto_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('clave', 120);         // ej: "Potencia", "Material"
            $table->string('valor', 255)->nullable(); // ej: "750W", "Acero"
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['producto_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_specs');
    }
};
