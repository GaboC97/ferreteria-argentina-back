<?php

namespace App\Http\Controllers;

use App\Models\CatalogoWeb;
use App\Services\PaljetService;
use Illuminate\Http\Request;

class PaljetCatalogoController extends Controller
{
    protected PaljetService $paljet;

    public function __construct(PaljetService $paljet)
    {
        $this->paljet = $paljet;
    }

    /**
     * Listar artículos desde el WS de Paljet.
     */
    public function index(Request $request)
    {
        // Soporte multi-marca: ?marca=BOSCH&marca=FMT  o  ?marcas=BOSCH,FMT
        // Soporta ?marca=BOSCH,MAKITA o ?marca[]=BOSCH&marca[]=MAKITA o ?marcas=BOSCH,MAKITA
        $marcasRaw = $request->query('marcas') ?? $request->query('marca');
        if (is_array($marcasRaw)) {
            $marcas = array_values(array_filter(array_map('trim', $marcasRaw)));
        } elseif ($marcasRaw) {
            $marcas = array_values(array_filter(array_map('trim', explode(',', $marcasRaw))));
        } else {
            $marcas = [];
        }

        // Acepta ?orden=precio_asc (frontend) o ?sort=precio_asc (legado)
        $orden = $request->query('orden') ?? $request->query('sort', 'relevancia');

        $filtros = array_filter([
            'page'        => $request->query('page', 0),
            'size'        => $request->query('size', 20),
            'descripcion' => $request->query('q'),
            'desc_mod_med'=> $request->query('desc_mod_med'),
            'codigo'      => $request->query('codigo'),
            'ean'         => $request->query('ean'),
            'marcas'      => $marcas ?: null,
            'familia'     => $request->query('familia'),
            'categoria'   => $request->query('categoria'),
            'en_oferta'   => $request->boolean('en_oferta') ? true : null,
            'sort'        => $orden,
        ], fn($v) => !is_null($v) && $v !== '');

        $data = $this->paljet->getArticulos($filtros);

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], $data['status'] ?? 500);
        }

        return response()->json($data);
    }

    /**
     * Árbol de categorías filtrado a las que tienen artículos publicados.
     * GET /api/catalogo/categorias
     */
    public function categorias()
    {
        $data = $this->paljet->getCategorias();

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], $data['status'] ?? 500);
        }

        return response()->json($data);
    }

    /**
     * Lista de marcas disponibles en el catálogo con conteo de artículos.
     * GET /api/catalogo/marcas
     */
    public function marcas()
    {
        return response()->json($this->paljet->getMarcas());
    }

    /**
     * Obtener un artículo por ID numérico de Paljet o por código string.
     */
    public function show(string $paljetId)
    {
        if (!is_numeric($paljetId)) {
            $articulo = CatalogoWeb::where('codigo', $paljetId)->first();

            if (!$articulo) {
                return response()->json(['error' => 'Artículo no encontrado'], 404);
            }

            $paljetId = $articulo->paljet_art_id;
        }

        $data = $this->paljet->getArticulo((int) $paljetId);

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], $data['status'] ?? 500);
        }

        return response()->json($data);
    }

    /**
     * Artículos con stock = 0 en dep_id=8 (Playa Unión). Solo admin.
     * GET /api/catalogo/sin-stock
     */
    public function sinStock(Request $request)
    {
        $page   = (int) $request->query('page', 0);
        $size   = (int) $request->query('size', 100);
        $search = trim($request->query('search', ''));

        $data = $this->paljet->getArticulosSinStock($page, $size, $search ?: null);

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], $data['status'] ?? 500);
        }

        return response()->json($data);
    }

    /**
     * Proxy de imagen de un artículo por código.
     * GET /api/catalogo/{codigo}/imagen
     */
    public function imagen(string $codigo)
    {
        $result = $this->paljet->getImagenArticulo($codigo);

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], $result['status'] ?? 404);
        }

        return response($result['body'], 200)
            ->header('Content-Type', $result['type'])
            ->header('Cache-Control', 'public, max-age=2592000');
    }
}
