<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contenedor_reservas', function (Blueprint $table) {
            $table->id();

            // Relación con el item del pedido (lo más importante)
            $table->unsignedBigInteger('pedido_item_id');

            // Redundancias útiles para consultas/reportes (opcionales pero recomendadas)
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->unsignedBigInteger('producto_id')->nullable();

            // Datos del alquiler
            $table->date('fecha_entrega');
            $table->date('fecha_retiro'); // fecha_entrega + 3 días (lo calculás en el Service)

            $table->string('localidad', 120);
            $table->string('domicilio', 180);
            $table->string('codigo_postal', 20)->nullable();
            $table->string('telefono', 40);

            $table->unsignedInteger('cantidad')->default(1);

            $table->boolean('cuenta_corriente')->default(false);
            $table->string('comprobante_path', 255)->nullable();

            // Operativo / control futuro
            $table->string('estado', 30)->default('pendiente'); 
            // estados sugeridos: pendiente, confirmada, entregada, retirada, cancelada

            $table->text('observaciones')->nullable();
            $table->date('fecha_retiro_real')->nullable();

            $table->timestamps();

            // FK: pedido_items
            $table->foreign('pedido_item_id')
                ->references('id')
                ->on('pedido_items')
                ->onDelete('cascade');

            // Índices para rendimiento (clave si alquilan muchos)
            $table->index(['fecha_entrega', 'estado']);
            $table->index('fecha_retiro');
            $table->index('pedido_id');
            $table->index('producto_id');

            // Si querés evitar duplicados por error (mismo item => 1 reserva)
            $table->unique('pedido_item_id');
        });

        // Si querés FK para pedido_id / producto_id y tus tablas están bien definidas:
        Schema::table('contenedor_reservas', function (Blueprint $table) {
            $table->foreign('pedido_id')
                ->references('id')
                ->on('pedidos')
                ->nullOnDelete();

            $table->foreign('producto_id')
                ->references('id')
                ->on('productos')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contenedor_reservas', function (Blueprint $table) {
            // Dropear FKs si existen
            $table->dropForeign(['pedido_item_id']);
            $table->dropForeign(['pedido_id']);
            $table->dropForeign(['producto_id']);
        });

        Schema::dropIfExists('contenedor_reservas');
    }
};
