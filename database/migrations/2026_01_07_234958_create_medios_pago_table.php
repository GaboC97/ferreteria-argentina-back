<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medios_pago', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 80);                 // "Mercado Pago"
            $table->string('codigo', 60)->unique();       // "mercadopago"
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medios_pago');
    }
};
