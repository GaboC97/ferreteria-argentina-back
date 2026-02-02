<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductoResource;
use App\Models\Producto;
use Illuminate\Http\Request;


class ProductoController extends Controller
{
    // GET /api/productos
public function index(Request $request)
{
    $q = Producto::query()
        ->where('activo', true)
        ->with(['imagenes', 'categoria', 'specs']); // si no tenés la tabla aún, podés sacar esto

    if ($search = trim((string) $request->query('q'))) {
        $q->where(function ($sub) use ($search) {
            $sub->where('nombre', 'like', "%{$search}%")
                ->orWhere('marca', 'like', "%{$search}%")
                ->orWhere('codigo', 'like', "%{$search}%");
        });
    }

if ($categoriaId = $request->query('categoria_id')) {
    $q->where('categoria_id', (int) $categoriaId);
}


    if ($request->boolean('destacado')) {
        $q->where('destacado', true);
    }


    if ($marcas = $request->input('marcas')) {
    $marcas = is_array($marcas) ? $marcas : explode(',', (string)$marcas);

    $q->whereHas('marca', function($sub) use ($marcas) {
        $sub->whereIn('nombre', $marcas);
    });
}


    $sort = $request->query('sort', 'nombre');
    $dir  = $request->query('dir', 'asc');
    if (!in_array($sort, ['nombre', 'precio', 'created_at'], true)) $sort = 'nombre';
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'asc';

    $q->orderBy($sort, $dir);

    $perPage = (int) $request->query('per_page', 12);
    $perPage = max(1, min($perPage, 50));

    return ProductoResource::collection(
    $q->paginate($perPage)->appends($request->query())
);

}


public function show(string $slug)
{
    $producto = Producto::query()
        ->where('activo', true)
        ->where('slug', $slug)
        ->with(['imagenes', 'categoria', 'specs'])
        ->firstOrFail();

    return new ProductoResource($producto);
}


public function marcas(Request $request)
{
    $q = Producto::query()->where('activo', true);

    if ($categoriaId = $request->query('categoria_id')) {
        $q->where('categoria_id', (int) $categoriaId);
    }

    if ($search = trim((string) $request->query('q'))) {
        $q->where(function ($sub) use ($search) {
            $sub->where('nombre', 'like', "%{$search}%")
                ->orWhere('codigo', 'like', "%{$search}%");
        });
    }

    // 👇 si ya estás filtrando por marcas desde el front
    $marcasSeleccionadas = $request->query('marcas');
    if (is_array($marcasSeleccionadas) && count($marcasSeleccionadas)) {
        $q->whereIn('marca', $marcasSeleccionadas); // o marca_id según tu modelo
    }

    $total = (clone $q)->count();

    $marcas = (clone $q)
        ->selectRaw("marca as nombre, COUNT(*) as cantidad")
        ->whereNotNull('marca')
        ->where('marca', '<>', '')
        ->groupBy('marca')
        ->orderBy('marca')
        ->get();

    return response()->json([
        'total_productos' => $total,
        'marcas' => $marcas,
    ]);
}




}
