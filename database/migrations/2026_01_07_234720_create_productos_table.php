<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();

            // Relación opcional a categoría
            $table->foreignId('categoria_id')->nullable()
                ->constrained('categorias')
                ->nullOnDelete();

            $table->string('nombre', 180);
            $table->string('slug', 200)->unique();

            // SKU/código interno o de barra (opcional)
            $table->string('codigo', 80)->nullable()->unique();

            $table->text('descripcion')->nullable();

            // Precios (en pesos) - usamos decimal por seguridad
            $table->decimal('precio', 12, 2);

            // Opcionales muy comunes en ferretería
            $table->string('marca', 100)->nullable();
            $table->string('unidad', 40)->nullable();   // "unidad", "metro", "kg", etc.

            // Si está visible para venta
            $table->boolean('activo')->default(true);

            // Para “destacado”/home
            $table->boolean('destacado')->default(false);

            $table->timestamps();

            $table->index(['categoria_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
