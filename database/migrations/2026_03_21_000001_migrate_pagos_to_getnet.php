<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar columnas de Getnet a la tabla pagos
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('getnet_payment_id', 120)->nullable()->index()->after('moneda');
            $table->string('getnet_order_id', 120)->nullable()->index()->after('getnet_payment_id');
            $table->string('getnet_idempotency_key', 64)->nullable()->after('getnet_order_id');
            $table->string('getnet_status', 60)->nullable()->after('getnet_idempotency_key');
            $table->string('getnet_checkout_url', 500)->nullable()->after('getnet_status');
            $table->json('getnet_raw_json')->nullable()->after('getnet_checkout_url');
        });

        // 2. Agregar columnas de refund genéricas si no existen (pueden venir de la migración anterior)
        if (!Schema::hasColumn('pagos', 'refund_monto')) {
            Schema::table('pagos', function (Blueprint $table) {
                $table->decimal('refund_monto', 12, 2)->nullable()->after('getnet_raw_json');
                $table->string('refund_status', 60)->nullable()->after('refund_monto');
                $table->timestamp('devuelto_en')->nullable()->after('refund_status');
            });
        }

        // 3. Reemplazar el medio de pago mercadopago → getnet en medios_pago
        $mp = DB::table('medios_pago')->where('codigo', 'mercadopago')->first();
        if ($mp) {
            DB::table('medios_pago')->where('codigo', 'mercadopago')->update([
                'nombre' => 'Getnet',
                'codigo' => 'getnet',
            ]);
        } else {
            // Si no existe, crear el medio de pago getnet
            DB::table('medios_pago')->insertOrIgnore([
                'nombre'     => 'Getnet',
                'codigo'     => 'getnet',
                'activo'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn([
                'getnet_payment_id',
                'getnet_order_id',
                'getnet_idempotency_key',
                'getnet_status',
                'getnet_checkout_url',
                'getnet_raw_json',
            ]);
        });

        // Revertir medio de pago
        DB::table('medios_pago')->where('codigo', 'getnet')->update([
            'nombre' => 'MercadoPago',
            'codigo' => 'mercadopago',
        ]);
    }
};
