<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('envios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_id')
                ->constrained('pedidos')
                ->cascadeOnDelete();

            // Snapshot de la dirección (para no depender de direcciones editables)
            $table->string('calle', 160);
            $table->string('numero', 20);
            $table->string('piso', 20)->nullable();
            $table->string('depto', 20)->nullable();

            $table->string('ciudad', 80);
            $table->string('provincia', 80);
            $table->string('codigo_postal', 20)->nullable();
            $table->string('referencias', 255)->nullable();

            // Datos de logística (opcional, para crecer)
            $table->enum('estado', ['pendiente', 'en_preparacion', 'despachado', 'entregado', 'cancelado'])
                ->default('pendiente');

            $table->string('empresa', 120)->nullable();       // OCA, Correo Argentino, moto, etc.
            $table->string('tracking_codigo', 120)->nullable();
            $table->timestamp('despachado_en')->nullable();
            $table->timestamp('entregado_en')->nullable();

            $table->timestamps();

            $table->unique('pedido_id'); // 1 envío por pedido
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios');
    }
};
