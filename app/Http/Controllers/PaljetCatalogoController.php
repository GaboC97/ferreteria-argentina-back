<?php

namespace App\Http\Controllers;

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
        $marcasRaw = $request->query('marcas');
        $marcaArr  = $request->query('marca');
        if ($marcasRaw) {
            $marcas = array_filter(array_map('trim', explode(',', $marcasRaw)));
        } elseif (is_array($marcaArr)) {
            $marcas = array_filter($marcaArr);
        } elseif ($marcaArr) {
            $marcas = [$marcaArr];
        } else {
            $marcas = [];
        }

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
            'sort'        => $request->query('sort', 'relevancia'),
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
     * Obtener un artículo por su ID de Paljet.
     */
    public function show(int $paljetId)
    {
        $data = $this->paljet->getArticulo($paljetId);

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
        $page = (int) $request->query('page', 0);
        $size = (int) $request->query('size', 100);

        $data = $this->paljet->getArticulosSinStock($page, $size);

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
