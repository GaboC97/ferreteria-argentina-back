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

        // 1) Update cliente (solo lo que corresponde)
        $clienteData = collect($data)->only([
            'nombre','apellido','telefono','dni','cuit','condicion_iva','nombre_empresa'
        ])->toArray();

        if (!empty($clienteData)) {
            $cliente->update($clienteData);
        }

        // Sync nombre con users
        if (array_key_exists('nombre', $data)) {
            $user->name = $data['nombre'] ?? $user->name;
            $user->save();
        }

        // 2) Guardar/actualizar dirección principal en direcciones
        $direccionLegacy = collect($data)->only([
            'direccion_calle',
            'direccion_numero',
            'direccion_piso',
            'direccion_depto',
            'direccion_localidad',
            'direccion_provincia',
            'direccion_codigo_postal',
        ])->toArray();

        $tieneDireccion = collect($direccionLegacy)->filter(function ($v) {
            return $v !== null && $v !== '';
        })->isNotEmpty();

        if ($tieneDireccion) {
            // map legacy -> tabla direcciones
            $direccionData = [
                'alias' => 'Principal',
                'nombre_recibe' => $cliente->nombre ?: null,
                'telefono_recibe' => $cliente->telefono ?: null,
                'calle' => $direccionLegacy['direccion_calle'] ?? null,
                'numero' => $direccionLegacy['direccion_numero'] ?? null,
                'piso' => $direccionLegacy['direccion_piso'] ?? null,
                'depto' => $direccionLegacy['direccion_depto'] ?? null,
                'ciudad' => $direccionLegacy['direccion_localidad'] ?? null,
                'provincia' => $direccionLegacy['direccion_provincia'] ?? null,
                'codigo_postal' => $direccionLegacy['direccion_codigo_postal'] ?? null,
                'referencias' => null,
                'es_principal' => true,
            ];

            // asegurar que solo haya una principal
            Direccion::where('cliente_id', $cliente->id)->update(['es_principal' => false]);

            $principal = Direccion::where('cliente_id', $cliente->id)
                ->where('es_principal', true)
                ->first();

            if ($principal) {
                $principal->update($direccionData);
            } else {
                $direccionData['cliente_id'] = $cliente->id;
                Direccion::create($direccionData);
            }
        }

        // Respuesta: cliente + dirección principal (útil para el front)
        $direccionPrincipal = Direccion::where('cliente_id', $cliente->id)
            ->where('es_principal', true)
            ->first();

        return response()->json([
            'message' => 'Perfil actualizado correctamente',
            'cliente' => $cliente->fresh(),
            'direccion_principal' => $direccionPrincipal,
        ]);
    });

}
}

