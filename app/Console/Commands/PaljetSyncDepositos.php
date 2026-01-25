<?php

namespace App\Console\Commands;

use App\Integrations\Paljet\Services\PaljetStockService;
use App\Models\PaljetDeposito;
use Illuminate\Console\Command;

class PaljetSyncDepositos extends Command
{
    protected $signature = 'paljet:sync-depositos';
    protected $description = 'Sincroniza depósitos desde PalJet a DB local.';

    public function handle(PaljetStockService $service): int
    {
        $data = $service->depositos();

        // viene como array plano
        $items = is_array($data) ? $data : [];
        $upserts = 0;

        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $depId = $it['idDeposito'] ?? null;
            if ($depId === null) continue;

            PaljetDeposito::updateOrCreate(
                ['paljet_id' => (int)$depId],
                [
                    'nombre' => (string)($it['nombre'] ?? 'SIN NOMBRE'),
                    'activo' => (($it['activo'] ?? 'S') === 'S'),
                    'raw_json' => $it,
                ]
            );

            $upserts++;
        }

        $this->info("Listo. Depósitos upsert: {$upserts}");
        return self::SUCCESS;
    }
}
