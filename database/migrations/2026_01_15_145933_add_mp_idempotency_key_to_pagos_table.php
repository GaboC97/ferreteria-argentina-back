<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            if (!Schema::hasColumn('pagos', 'mp_idempotency_key')) {
                $table->string('mp_idempotency_key', 64)
                      ->nullable()
                      ->after('mp_payment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            if (Schema::hasColumn('pagos', 'mp_idempotency_key')) {
                $table->dropColumn('mp_idempotency_key');
            }
        });
    }
};


