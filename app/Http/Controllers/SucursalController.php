<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    /**
     * GET /api/sucursales
     * Para selects y listados simples
     */
    public function index(Request $request)
    {
        $sucursales = Sucursal::query()
            ->select([
                'id',
                'nombre',
                'ciudad',
                'direccion',
                'telefono',
                'activo',
            ])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => $sucursales,
        ]);
    }

    /**
     * GET /api/sucursales/{id}
     * Detalle de una sucursal
     */
    public function show(int $id)
    {
        $sucursal = Sucursal::query()
            ->with(['stock'])
            ->findOrFail($id);

        return response()->json([
            'data' => $sucursal,
        ]);
    }
}
