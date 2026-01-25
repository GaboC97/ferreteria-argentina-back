<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();

            $table->string('proveedor', 60);      // "mercadopago"
            $table->string('evento', 120)->nullable();
            $table->string('external_id', 180)->nullable(); // id del pago/merchant_order/etc

            $table->json('payload_json');
            $table->boolean('procesado')->default(false);
            $table->timestamp('procesado_en')->nullable();

            $table->timestamps();

            $table->index(['proveedor', 'procesado']);
            $table->index(['external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
