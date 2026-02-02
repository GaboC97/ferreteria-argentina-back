<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contenedor_reservas', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('pedido_item_id');
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->unsignedBigInteger('producto_id')->nullable();

            // Datos del alquiler
            $table->date('fecha_entrega');
            $table->string('franja_entrega', 20)->nullable();
            $table->date('fecha_retiro');
            $table->string('franja_retiro', 20)->nullable();

            $table->string('localidad', 120);
            $table->string('domicilio', 180);
            $table->string('codigo_postal', 20)->nullable();
            $table->string('telefono', 40);

            $table->unsignedInteger('cantidad')->default(1);

            $table->boolean('cuenta_corriente')->default(false);
            $table->string('comprobante_path', 255)->nullable();

            // Estado y emails
            $table->string('estado', 30)->default('pendiente');
            $table->timestamp('email_enviado_at')->nullable();
            $table->timestamp('email_admin_enviado_at')->nullable();

            $table->text('observaciones')->nullable();
            $table->date('fecha_retiro_real')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('pedido_item_id')
                ->references('id')->on('pedido_items')
                ->onDelete('cascade');

            $table->foreign('pedido_id')
                ->references('id')->on('pedidos')
                ->nullOnDelete();

            $table->foreign('producto_id')
                ->references('id')->on('productos')
                ->nullOnDelete();

            // Indices
            $table->index(['fecha_entrega', 'estado']);
            $table->index('fecha_retiro');
            $table->index('pedido_id');
            $table->index('producto_id');
            $table->unique('pedido_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contenedor_reservas');
    }
};
