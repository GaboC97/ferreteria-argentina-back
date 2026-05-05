<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Direccion;


class ClienteController extends Controller
{
    /**
     * Actualizar datos personales del cliente (NO direcciones).
     */
   public function updatePerfil(Request $request): JsonResponse
{
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Usuario no autenticado'], 401);
    }

    $cliente = Cliente::where('user_id', $user->id)->first();

    if (!$cliente) {
        return response()->json(['message' => 'No hay cliente asociado a este usuario'], 404);
    }

    // ✅ Validamos TODO junto (cliente + direccion legacy del front)
    $data = $request->validate([
        // Cliente
        'nombre' => ['sometimes','string','max:120'],
        'apellido' => ['sometimes','string','max:120'],
        'telefono' => ['sometimes','string','max:40'],
        'dni' => ['sometimes','string','max:20'],
        'cuit' => ['nullable','string','max:20'],
        'condicion_iva' => ['nullable','string','max:80'],
        'nombre_empresa' => ['nullable','string','max:160'],

        // Dirección (viene como direccion_* desde tu Vue)
        'direccion_calle' => ['nullable','string','max:160'],
        'direccion_numero' => ['nullable','string','max:20'],
        'direccion_piso' => ['nullable','string','max:20'],
        'direccion_depto' => ['nullable','string','max:20'],
        'direccion_localidad' => ['nullable','string','max:80'],
        'direccion_provincia' => ['nullable','string','max:80'],
        'direccion_codigo_postal' => ['nullable','string','max:20'],
    ]);

    return DB::transaction(function () use ($data, $cliente, $user) {

        // 1) Campos del cliente (personales + direccion_* legacy en clientes table)
        $clienteData = collect($data)->only([
            'nombre', 'apellido', 'telefono', 'dni', 'cuit', 'condicion_iva', 'nombre_empresa',
            'direccion_calle', 'direccion_numero', 'direccion_piso', 'direccion_depto',
            'direccion_localidad', 'direccion_provincia', 'direccion_codigo_postal',
        ])->toArray();

        if (!empty($clienteData)) {
            $cliente->update($clienteData);
        }

        // Sync nombre con users
        if (array_key_exists('nombre', $data)) {
            $user->name = $data['nombre'] ?? $user->name;
            $user->save();
        }

        // 2) Guardar/actualizar dirección principal en tabla direcciones
        $calle    = $data['direccion_calle'] ?? null;
        $ciudad   = $data['direccion_localidad'] ?? null;
        $provincia = $data['direccion_provincia'] ?? null;

        // Solo persistir si hay al menos calle + ciudad + provincia (columnas NOT NULL)
        if ($calle && $ciudad && $provincia) {
            $direccionData = [
                'alias'           => 'Principal',
                'nombre_recibe'   => $cliente->nombre ?: null,
                'telefono_recibe' => $cliente->telefono ?: null,
                'calle'           => $calle,
                'numero'          => $data['direccion_numero'] ?? '',
                'piso'            => $data['direccion_piso'] ?? null,
                'depto'           => $data['direccion_depto'] ?? null,
                'ciudad'          => $ciudad,
                'provincia'       => $provincia,
                'codigo_postal'   => $data['direccion_codigo_postal'] ?? null,
                'referencias'     => null,
                'es_principal'    => true,
            ];

            // Buscar la principal ANTES de tocar el flag
            $principal = Direccion::where('cliente_id', $cliente->id)
                ->where('es_principal', true)
                ->first();

            // Marcar el resto como no-principales
            Direccion::where('cliente_id', $cliente->id)
                ->where('id', '!=', $principal?->id ?? 0)
                ->update(['es_principal' => false]);

            if ($principal) {
                $principal->update($direccionData);
            } else {
                $direccionData['cliente_id'] = $cliente->id;
                Direccion::create($direccionData);
            }
        }

        // Respuesta: cliente + dirección principal
        $direccionPrincipal = Direccion::where('cliente_id', $cliente->id)
            ->where('es_principal', true)
            ->first();

        return response()->json([
            'message'             => 'Perfil actualizado correctamente',
            'cliente'             => $cliente->fresh(),
            'direccion_principal' => $direccionPrincipal,
        ]);
    });

}
}

