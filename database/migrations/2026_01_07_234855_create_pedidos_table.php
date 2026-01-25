<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();

            // Cliente opcional (guest checkout permitido)
            $table->foreignId('cliente_id')->nullable()
                ->constrained('clientes')
                ->nullOnDelete();

            // Sucursal elegida (de donde se descuenta stock; para retiro es la misma)
            $table->foreignId('sucursal_id')
                ->constrained('sucursales')
                ->restrictOnDelete();

            // Retiro o envío
            $table->enum('tipo_entrega', ['retiro_sucursal', 'envio'])->default('retiro_sucursal');

            // Snapshot de contacto (siempre lo guardamos para que el pedido sea consistente)
            $table->string('nombre_contacto', 160);
            $table->string('email_contacto', 160);
            $table->string('telefono_contacto', 40)->nullable();

            // Estado del pedido (independiente del pago)
            $table->enum('estado', [
                'borrador',
                'pendiente_pago',
                'pagado',
                'en_preparacion',
                'listo_para_retiro',
                'enviado',
                'entregado',
                'cancelado',
                'fallido'
            ])->default('pendiente_pago');

            // Totales
            $table->decimal('total_productos', 12, 2)->default(0);
            $table->decimal('costo_envio', 12, 2)->default(0);
            $table->decimal('total_final', 12, 2)->default(0);
            $table->string('moneda', 3)->default('ARS');

            // Notas internas / del cliente
            $table->string('nota_cliente', 255)->nullable();
            $table->string('nota_interna', 255)->nullable();

            $table->timestamps();

            $table->index(['cliente_id', 'estado']);
            $table->index(['sucursal_id', 'tipo_entrega']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
