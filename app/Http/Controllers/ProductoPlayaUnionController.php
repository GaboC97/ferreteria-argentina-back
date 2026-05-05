<?php

namespace App\Http\Controllers;

use App\Services\ProductoFotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoPlayaUnionController extends Controller
{
    public function __construct(
        private readonly ProductoFotoService $service
    ) {}

    /**
     * POST /api/playa-union/login
     *
     * Valida la contraseña y devuelve éxito si es correcta.
     * El frontend puede usarlo para verificar acceso antes de mostrar la UI.
     */
    public function login(Request $request): JsonResponse
    {
        $expected = config('app.productos_password');
        $provided = $request->input('password');

        if (!$expected || !hash_equals((string) $expected, (string) $provided)) {
            return response()->json(['message' => 'Contraseña incorrecta.'], 401);
        }

        $token = hash('sha256', (string) $expected);

        return response()->json(['token' => $token]);
    }

    /**
     * GET /api/productos-playa-union
     *
     * Devuelve los productos de Playa Unión con el estado de foto.
     *
     * Query params opcionales:
     *   - sin_stock=true  → solo productos sin stock
     *   - sin_foto=true   → solo productos sin foto (tiene_foto = false)
     *   - con_foto=true   → solo productos con foto (tiene_foto = true)
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'sin_stock' => $request->boolean('sin_stock'),
            'sin_foto'  => $request->boolean('sin_foto'),
            'con_foto'  => $request->boolean('con_foto'),
        ];

        $productos = $this->service->getProductosConFotos($filtros);

        return response()->json($productos);
    }

    /**
     * POST /api/playa-union/productos/fotos
     *
     * Guarda el estado tiene_foto para uno o varios productos.
     *
     * Body: { "productos": [ { "id": 1234, "tiene_foto": true }, ... ] }
     */
    public function storeFotos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productos'              => ['required', 'array'],
            'productos.*.id'         => ['required', 'integer'],
            'productos.*.tiene_foto' => ['required', 'boolean'],
        ]);

        $this->service->guardarFotos($data['productos']);

        return response()->json(['message' => 'Fotos actualizadas correctamente.']);
    }
}
