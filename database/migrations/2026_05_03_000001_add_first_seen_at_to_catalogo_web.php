<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->timestamp('first_seen_at')->nullable()->after('synced_at');
            $table->index('first_seen_at', 'idx_catalogo_web_first_seen_at');
        });

        // Backfill: las filas existentes arrancan con synced_at (o NOW si está null).
        // A partir de acá, los inserts nuevos del sync van a tener su propio timestamp.
        DB::statement('UPDATE catalogo_web SET first_seen_at = COALESCE(synced_at, NOW()) WHERE first_seen_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->dropIndex('idx_catalogo_web_first_seen_at');
            $table->dropColumn('first_seen_at');
        });
    }
};
