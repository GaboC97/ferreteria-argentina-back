<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirarReservasStock extends Command
{
    protected $signature = 'reservas:expirar';
    protected $description = 'Marca reservas_stock activas vencidas y falla pedidos pendientes';

    public function handle()
    {
        $now = now();

        DB::transaction(function () use ($now) {

            // 1) Buscar pedidos que tengan reservas activas vencidas
            $pedidoIds = DB::table('reservas_stock')
                ->where('estado', 'activa')
                ->where('vence_en', '<', $now)
                ->pluck('pedido_id')
                ->unique()
                ->values();

            if ($pedidoIds->isEmpty()) {
                return;
            }

            // 2) Marcar reservas como vencidas
            DB::table('reservas_stock')
                ->where('estado', 'activa')
                ->where('vence_en', '<', $now)
                ->update([
                    'estado' => 'vencida',
                    'updated_at' => now(),
                ]);

            // 3) Marcar pedidos como fallido si siguen pendiente_pago
            DB::table('pedidos')
                ->whereIn('id', $pedidoIds)
                ->where('estado', 'pendiente_pago')
                ->update([
                    'estado' => 'fallido',
                    'updated_at' => now(),
                ]);

            // 4) Cancelar contenedores asociados a esos pedidos (si aplica)
            // (Opcional: si querés, lo hacemos por pedido en loop para ser prolijos)
        });

        $this->info('Reservas expiradas procesadas.');
        return Command::SUCCESS;
    }
}
