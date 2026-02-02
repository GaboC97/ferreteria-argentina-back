<?php

namespace App\Http\Controllers;

use App\Models\Marca;
use Illuminate\Http\Request;

class MarcaController extends Controller
{
    public function index(Request $request)
    {
        $q = Marca::query()
            ->where('activo', true)
            ->withCount(['productos as cantidad_productos' => function ($sub) use ($request) {

                // ⬇️ si querés que respete filtros del catálogo:
                if ($search = trim((string) $request->query('q'))) {
                    $sub->where(function ($s) use ($search) {
                        $s->where('nombre', 'like', "%{$search}%")
                          ->orWhere('codigo', 'like', "%{$search}%");
                    });
                }

                if ($categoriaId = $request->query('categoria_id')) {
                    $sub->where('categoria_id', (int) $categoriaId);
                }

                if ($request->boolean('destacado')) {
                    $sub->where('destacado', true);
                }

                $sub->where('activo', true);
            }])
            ->orderBy('nombre');

        $marcas = $q->get()
            ->filter(fn($m) => (int)$m->cantidad_productos > 0)
            ->values();

        return response()->json([
            'total_marcas' => $marcas->count(),
            'marcas' => $marcas->map(fn($m) => [
                'id' => $m->id,
                'nombre' => $m->nombre,
                'slug' => $m->slug,
                'cantidad' => (int) $m->cantidad_productos,
            ]),
        ]);
    }
}
