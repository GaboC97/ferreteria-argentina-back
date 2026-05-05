<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->decimal('precio_neto', 14, 2)->default(0)->after('precio');
            $table->decimal('iva_alicuota', 5, 2)->default(0)->after('precio_neto');
        });

        // Backfill desde raw_json: extrae pr_venta de la primera lista con pr_final > 0
        // y la alícuota de impuestos[0].impuesto.alicuota.
        // Se procesa fila por fila porque MySQL no expone JSON_EXTRACT con array filtering simple.
        DB::table('catalogo_web')->whereNotNull('raw_json')->orderBy('paljet_art_id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->raw_json, true);
                if (!is_array($data)) {
                    continue;
                }

                $prVenta = 0.0;
                foreach ($data['listas'] ?? [] as $lista) {
                    if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                        $prVenta = (float) ($lista['pr_venta'] ?? 0);
                        break;
                    }
                }

                $alicuota = (float) ($data['impuestos'][0]['impuesto']['alicuota'] ?? 0);

                DB::table('catalogo_web')
                    ->where('paljet_art_id', $row->paljet_art_id)
                    ->update([
                        'precio_neto'  => $prVenta,
                        'iva_alicuota' => $alicuota,
                    ]);
            }
        }, 'paljet_art_id');
    }

    public function down(): void
    {
        Schema::table('catalogo_web', function (Blueprint $table) {
            $table->dropColumn(['precio_neto', 'iva_alicuota']);
        });
    }
};
