<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('mp_refund_id', 120)->nullable()->after('mp_raw_json');
            $table->decimal('refund_monto', 12, 2)->nullable()->after('mp_refund_id');
            $table->string('refund_status', 60)->nullable()->after('refund_monto');
            $table->timestamp('devuelto_en')->nullable()->after('refund_status');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn(['mp_refund_id', 'refund_monto', 'refund_status', 'devuelto_en']);
        });
    }
};
