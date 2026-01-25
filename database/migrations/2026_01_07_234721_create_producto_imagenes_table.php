<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('producto_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnDelete();

            $table->string('url', 500);        // url o path
            $table->boolean('principal')->default(false);
            $table->unsignedInteger('orden')->default(1);

            $table->timestamps();

            $table->index(['producto_id', 'principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_imagenes');
    }
};
