<?php

namespace App\Http\Controllers;

use App\Models\CatalogoWeb;
use App\Models\PaljetArticuloOculto;
use App\Services\PaljetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaljetArticulosOcultosController extends Controller
{
    /**
     * GET /admin/catalogo/ocultos
     * Lista todos los artículos ocultos.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            PaljetArticuloOculto::orderByDesc('created_at')->get()
        );
    }

    /**
     * POST /admin/catalogo/ocultos
     * Oculta un artículo del catálogo web por su paljet_art_id.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paljet_art_id' => ['required', 'integer', 'unique:paljet_articulos_ocultos,paljet_art_id'],
            'motivo'        => ['nullable', 'string', 'max:255'],
        ]);

        $oculto = PaljetArticuloOculto::create($data);

        // Eliminarlo de catalogo_web de forma inmediata (sin esperar el próximo sync)
        CatalogoWeb::where('paljet_art_id', $data['paljet_art_id'])->delete();

        return response()->json($oculto, 201);
    }

    /**
     * DELETE /admin/catalogo/ocultos/{paljetArtId}
     * Vuelve a mostrar un artículo (lo quita de la lista de ocultos).
     */
    public function destroy(int $paljetArtId, PaljetService $paljet): JsonResponse
    {
        $deleted = PaljetArticuloOculto::where('paljet_art_id', $paljetArtId)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Artículo no encontrado en la lista de ocultos'], 404);
        }

        // Reinsertar inmediatamente en catalogo_web sin esperar el próximo sync
        try {
            $art = $paljet->getArticulo($paljetArtId);

            if (!isset($art['error'])) {
                $umbral          = (int) config('services.paljet.stock_alerta', 3);
                $contenedorArtId = (int) config('services.paljet.contenedor_art_id', 12441);

                $precio = 0.0;
                foreach ($art['listas'] ?? [] as $lista) {
                    if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                        $precio = (float) $lista['pr_final'];
                        break;
                    }
                }
                if ($precio === 0.0 && isset($art['pr_final'])) {
                    $precio = (float) $art['pr_final'];
                }

                if ($precio > 0) {
                    $adminExistencia = $paljetArtId === $contenedorArtId
                        ? false
                        : (bool) ($art['admin_existencia'] ?? false);
                    $stock           = (float) ($art['stock_disponible'] ?? 0);
                    $ultimasUnidades = $adminExistencia && $stock > 0 && $stock <= $umbral;
                    $codigo          = $art['codigo'] ?? null;
                    $marca           = is_array($art['marca']    ?? null) ? $art['marca']    : null;
                    $familia         = is_array($art['familia']  ?? null) ? $art['familia']  : null;
                    $categoria       = is_array($art['categoria'] ?? null) ? $art['categoria'] : null;

                    $listas = [];
                    foreach ($art['listas'] ?? [] as $l) {
                        $listas[] = array_intersect_key($l, array_flip([
                            'lista', 'lista_id', 'lista_nombre', 'pr_final', 'pr_vta', 'nombre',
                        ]));
                    }

                    CatalogoWeb::upsert([[
                        'paljet_art_id'    => $paljetArtId,
                        'codigo'           => $codigo,
                        'ean'              => $art['ean'] ?? null,
                        'descripcion'      => $art['descripcion'] ?? null,
                        'desc_cliente'     => $art['desc_cliente'] ?? null,
                        'desc_mod_med'     => $art['desc_mod_med'] ?? null,
                        'marca_id'         => $marca ? (int) ($marca['id'] ?? 0) : null,
                        'marca_nombre'     => $marca ? ($marca['nombre'] ?? null) : null,
                        'familia_id'       => $familia ? (int) ($familia['id'] ?? 0) : null,
                        'familia_nombre'   => $familia ? ($familia['nombre'] ?? null) : null,
                        'categoria_id'     => $categoria ? (int) ($categoria['id'] ?? 0) : null,
                        'categoria_nombre' => $categoria ? ($categoria['nombre'] ?? null) : null,
                        'precio'           => $precio,
                        'admin_existencia' => $adminExistencia,
                        'stock'            => $stock,
                        'ultimas_unidades' => $ultimasUnidades,
                        'imagen_url'       => $codigo ? url("/api/catalogo/{$codigo}/imagen") : null,
                        'listas_json'      => json_encode($listas),
                        'raw_json'         => json_encode($art),
                        'synced_at'        => now()->toDateTimeString(),
                    ]], ['paljet_art_id'], [
                        'codigo', 'ean', 'descripcion', 'desc_cliente', 'desc_mod_med',
                        'marca_id', 'marca_nombre', 'familia_id', 'familia_nombre',
                        'categoria_id', 'categoria_nombre', 'precio', 'admin_existencia',
                        'stock', 'ultimas_unidades', 'imagen_url', 'listas_json', 'raw_json', 'synced_at',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Si falla el ERP, igual fue quitado de ocultos — el sync de los 15 min lo va a traer
            \Illuminate\Support\Facades\Log::warning("No se pudo reinsertar art {$paljetArtId} en catalogo_web: " . $e->getMessage());
        }

        return response()->json(['message' => 'Artículo visible nuevamente y disponible en el catálogo.']);
    }
}
