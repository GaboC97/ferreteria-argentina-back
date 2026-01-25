<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('slug', 140)->unique(); // para URL/SEO: "herramientas-manuales"
            $table->foreignId('categoria_padre_id')->nullable()
                ->constrained('categorias')
                ->nullOnDelete();

            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('categoria_padre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
