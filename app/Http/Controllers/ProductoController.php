<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
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

    $sort = $request->query('sort', 'nombre');
    $dir  = $request->query('dir', 'asc');
    if (!in_array($sort, ['nombre', 'precio', 'created_at'], true)) $sort = 'nombre';
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'asc';

    $q->orderBy($sort, $dir);

    $perPage = (int) $request->query('per_page', 12);
    $perPage = max(1, min($perPage, 50));

    return ProductoResource::collection($q->paginate($perPage));
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

}
