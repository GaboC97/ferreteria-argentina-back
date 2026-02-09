<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Direccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DireccionController extends Controller
{
    private function getClienteFromRequest(Request $request): ?Cliente
    {
        $user = $request->user();
        if (!$user) return null;

        return Cliente::where('user_id', $user->id)->first();
    }

    public function index(Request $request): JsonResponse
    {
        $cliente = $this->getClienteFromRequest($request);

        if (!$cliente) {
            return response()->json([
                'message' => 'Cliente no encontrado o usuario no autenticado'
            ], 404);
        }

        $direcciones = Direccion::where('cliente_id', $cliente->id)
            ->orderByDesc('es_principal')
            ->get();

        return response()->json([
            'direcciones' => $direcciones
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $cliente = $this->getClienteFromRequest($request);

        if (!$cliente) {
            return response()->json([
                'message' => 'Cliente no encontrado o usuario no autenticado'
            ], 404);
        }

        $data = $request->validate([
            'alias' => ['nullable','string','max:80'],
            'nombre_recibe' => ['nullable','string','max:120'],
            'telefono_recibe' => ['nullable','string','max:40'],
            'calle' => ['required','string','max:160'],
            'numero' => ['required','string','max:20'],
            'piso' => ['nullable','string','max:20'],
            'depto' => ['nullable','string','max:20'],
            'ciudad' => ['required','string','max:80'],
            'provincia' => ['required','string','max:80'],
            'codigo_postal' => ['required','string','max:20'],
            'referencias' => ['nullable','string','max:255'],
            'es_principal' => ['boolean'],
        ]);

        if (!empty($data['es_principal'])) {
            Direccion::where('cliente_id', $cliente->id)->update(['es_principal' => false]);
        }

        $data['cliente_id'] = $cliente->id;
        $data['es_principal'] = $data['es_principal'] ?? false;

        $direccion = Direccion::create($data);

        return response()->json([
            'message' => 'Dirección creada',
            'direccion' => $direccion
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cliente = $this->getClienteFromRequest($request);

        if (!$cliente) {
            return response()->json([
                'message' => 'Cliente no encontrado o usuario no autenticado'
            ], 404);
        }

        $direccion = Direccion::where('cliente_id', $cliente->id)
            ->where('id', $id)
            ->first();

        if (!$direccion) {
            return response()->json(['message' => 'Dirección no encontrada'], 404);
        }

        $data = $request->validate([
            'alias' => ['nullable','string','max:80'],
            'nombre_recibe' => ['nullable','string','max:120'],
            'telefono_recibe' => ['nullable','string','max:40'],
            'calle' => ['sometimes','string','max:160'],
            'numero' => ['sometimes','string','max:20'],
            'piso' => ['nullable','string','max:20'],
            'depto' => ['nullable','string','max:20'],
            'ciudad' => ['sometimes','string','max:80'],
            'provincia' => ['sometimes','string','max:80'],
            'codigo_postal' => ['sometimes','string','max:20'],
            'referencias' => ['nullable','string','max:255'],
            'es_principal' => ['boolean'],
        ]);

        if (!empty($data['es_principal'])) {
            Direccion::where('cliente_id', $cliente->id)->update(['es_principal' => false]);
        }

        $direccion->update($data);

        return response()->json([
            'message' => 'Dirección actualizada',
            'direccion' => $direccion->fresh()
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $cliente = $this->getClienteFromRequest($request);

        if (!$cliente) {
            return response()->json([
                'message' => 'Cliente no encontrado o usuario no autenticado'
            ], 404);
        }

        $direccion = Direccion::where('cliente_id', $cliente->id)
            ->where('id', $id)
            ->first();

        if (!$direccion) {
            return response()->json(['message' => 'Dirección no encontrada'], 404);
        }

        $direccion->delete();

        return response()->json([
            'message' => 'Dirección eliminada'
        ]);
    }
}
