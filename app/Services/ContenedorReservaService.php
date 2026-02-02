<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContenedorReservaService
{
    public function createOrUpdateFromPedidoItemId(
        int $pedidoItemId,
        int $pedidoId,
        int $productoId,
        int $cantidad,
        array $extras,
        int $diasAlquiler = 3
    ): void {
        $validator = Validator::make($extras, [
            'fecha_entrega'   => ['required', 'date'],

            'localidad'       => ['required', 'string', 'max:120'],
            'domicilio'       => ['required', 'string', 'max:180'],
            'codigo_postal'   => ['nullable', 'string', 'max:20'],
            'telefono'        => ['required', 'string', 'max:40'],

            'cuenta_corriente' => ['nullable', 'boolean'],
            'comprobante_path' => ['nullable', 'string', 'max:255'],

            'observaciones'    => ['nullable', 'string'],
            'referencia'       => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $fechaEntrega = Carbon::parse($extras['fecha_entrega'])->startOfDay();
        $fechaRetiro  = (clone $fechaEntrega)->addDays($diasAlquiler)->startOfDay();

        // Observaciones consolidadas (observaciones + referencia)
        $observacionesFinal = collect([
            !empty($extras['observaciones']) ? trim($extras['observaciones']) : null,
            !empty($extras['referencia']) ? 'Referencia: ' . trim($extras['referencia']) : null,
        ])->filter()->implode(' | ');

        // 1) Snapshot en pedido_items.extras (con datos calculados)
        $snapshotExtras = array_merge($extras, [
            'tipo' => 'contenedor',
            'dias_alquiler' => $diasAlquiler,
            'fecha_retiro_calculada' => $fechaRetiro->toDateString(),
        ]);

        DB::table('pedido_items')
            ->where('id', $pedidoItemId)
            ->update([
                'extras' => json_encode($snapshotExtras),
                'updated_at' => now(),
            ]);

        // 2) Upsert contenedor_reservas (idempotente por pedido_item_id)
        $now = now();

        DB::table('contenedor_reservas')->updateOrInsert(
            ['pedido_item_id' => $pedidoItemId],
            [
                'pedido_id'        => $pedidoId,
                'producto_id'      => $productoId,

                'fecha_entrega'    => $fechaEntrega->toDateString(),
                'fecha_retiro'     => $fechaRetiro->toDateString(),

                'localidad'        => $extras['localidad'],
                'domicilio'        => $extras['domicilio'],
                'codigo_postal'    => $extras['codigo_postal'] ?? null,
                'telefono'         => $extras['telefono'],

                'cantidad'         => $cantidad,
                'cuenta_corriente' => (bool)($extras['cuenta_corriente'] ?? false),
                'comprobante_path' => $extras['comprobante_path'] ?? null,

                'estado'           => $this->estadoParaUpsert($pedidoItemId),
                'observaciones'    => $observacionesFinal ?: null,

                'updated_at'       => $now,
                'created_at'       => $this->createdAtParaUpsert($pedidoItemId, $now),
            ]
        );
    }

    private function estadoParaUpsert(int $pedidoItemId): string
    {
        $actual = DB::table('contenedor_reservas')
            ->where('pedido_item_id', $pedidoItemId)
            ->value('estado');

        if ($actual === 'confirmada') return 'confirmada';
        if ($actual === 'cancelada')  return 'cancelada';

        return 'pendiente';
    }

    private function createdAtParaUpsert(int $pedidoItemId, $now)
    {
        $created = DB::table('contenedor_reservas')
            ->where('pedido_item_id', $pedidoItemId)
            ->value('created_at');

        return $created ?: $now;
    }

    public function confirmarPorPedido(int $pedidoId): void
    {
        DB::table('contenedor_reservas')
            ->where('pedido_id', $pedidoId)
            ->where('estado', 'pendiente')
            ->update([
                'estado' => 'confirmada',
                'updated_at' => now(),
            ]);
    }

    public function cancelarPorPedido(int $pedidoId): void
    {
        DB::table('contenedor_reservas')
            ->where('pedido_id', $pedidoId)
            ->whereIn('estado', ['pendiente', 'confirmada'])
            ->update([
                'estado' => 'cancelada',
                'updated_at' => now(),
            ]);
    }
}
