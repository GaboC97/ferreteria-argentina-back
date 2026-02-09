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

        $extras['tipo'] = $extras['tipo'] ?? 'contenedor';

        $diasAlquilerFinal = (int)($extras['dias_alquiler'] ?? $diasAlquiler);
        $diasAlquilerFinal = max(1, min(60, $diasAlquilerFinal));

        $validator = Validator::make($extras, [
            'tipo'           => ['required', 'in:contenedor'],
            'fecha_entrega'  => ['required', 'date'],
            'localidad'      => ['required', 'string', 'max:120'],
            'domicilio'      => ['required', 'string', 'max:180'],
            'codigo_postal'  => ['nullable', 'string', 'max:20'],
            'telefono'       => ['required', 'string', 'max:40'],
            'cuenta_corriente' => ['nullable', 'boolean'],
            'comprobante_path' => ['nullable', 'string', 'max:255'],
            'observaciones'    => ['nullable', 'string'],
            'referencia'       => ['nullable', 'string', 'max:255'],
            'dias_alquiler'    => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $fechaEntrega = Carbon::parse($extras['fecha_entrega'])->startOfDay();
        $fechaRetiro  = (clone $fechaEntrega)->addDays($diasAlquilerFinal)->startOfDay();

        $observacionesFinal = collect([
            !empty($extras['observaciones']) ? trim($extras['observaciones']) : null,
            !empty($extras['referencia']) ? 'Referencia: ' . trim($extras['referencia']) : null,
        ])->filter()->implode(' | ');

        // Snapshot en pedido_items.extras
        $snapshotExtras = array_merge($extras, [
            'tipo' => 'contenedor',
            'dias_alquiler' => $diasAlquilerFinal,
            'fecha_retiro_calculada' => $fechaRetiro->toDateString(),
        ]);

        DB::table('pedido_items')
            ->where('id', $pedidoItemId)
            ->update([
                'extras' => json_encode($snapshotExtras),
                'updated_at' => now(),
            ]);

        // Upsert contenedor_reservas
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
        if ($actual === 'devuelta')   return 'devuelta';

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

    // ✅ Cancelación PRE-PAGO (solo pendientes)
    public function cancelarPorPedido(int $pedidoId): void
    {
        DB::table('contenedor_reservas')
            ->where('pedido_id', $pedidoId)
            ->where('estado', 'pendiente')
            ->update([
                'estado' => 'cancelada',
                'updated_at' => now(),
            ]);
    }

    // ✅ Devolución POST-PAGO (confirmadas → devueltas)
    public function devolverPorPedido(int $pedidoId, ?string $motivo = null): void
    {
        DB::table('contenedor_reservas')
            ->where('pedido_id', $pedidoId)
            ->where('estado', 'confirmada')
            ->update([
                'estado' => 'devuelta',
                'devuelta_en' => now(),
                'motivo_devolucion' => $motivo ? mb_substr($motivo, 0, 255) : null,
                'updated_at' => now(),
            ]);
    }
}
