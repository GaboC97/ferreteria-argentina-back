<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_id')
                ->constrained('pedidos')
                ->cascadeOnDelete();

            $table->foreignId('medio_pago_id')
                ->constrained('medios_pago')
                ->restrictOnDelete();

            $table->enum('estado', [
                'iniciado',
                'pendiente',
                'aprobado',
                'rechazado',
                'cancelado',
            ])->default('iniciado');

            $table->decimal('monto', 12, 2);
            $table->string('moneda', 3)->default('ARS');

            // Mercado Pago
            $table->string('mp_preference_id', 120)->nullable()->index();
            $table->string('mp_payment_id', 120)->nullable()->index();
            $table->string('mp_idempotency_key', 64)->nullable();
            $table->string('mp_merchant_order_id', 120)->nullable()->index();

            $table->string('mp_status', 60)->nullable();
            $table->string('mp_status_detail', 120)->nullable();

            $table->json('mp_raw_json')->nullable();

            $table->timestamp('aprobado_en')->nullable();

            $table->timestamps();

            $table->index(['pedido_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
