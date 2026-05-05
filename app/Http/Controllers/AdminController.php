<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\CatalogoWeb;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Configuracion;
use App\Models\PaljetOferta;
use App\Services\DashboardService;
use App\Services\PaljetService;

class AdminController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}
    /**
     * Dashboard principal con todas las estadísticas
     */
    public function dashboard()
    {
        return response()->json($this->dashboardService->getStats(), 200);
    }

    /**
     * Listar todos los clientes (admin)
     */
    public function clientes(Request $request)
    {
        $query = Cliente::with('user');

        // Filtros opcionales
        if ($request->has('activo')) {
            $query->where('activo', $request->activo);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $clientes = $query->paginate($perPage);

        return response()->json($clientes, 200);
    }

    /**
     * Listar artículos del catálogo Paljet (admin)
     * Responde con el mismo formato que GET /api/catalogo (Paljet page format).
     */
    public function productos(Request $request)
    {
        $size    = min((int) $request->query('per_page', 20), 100);
        $pageRaw = (int) $request->query('page', 1);
        $page    = $pageRaw > 0 ? $pageRaw - 1 : 0; // normalizar a base-0

        // Filtro sin_stock: artículos publicados con stock = 0 en dep principal
        if ($request->query('sin_stock') == '1') {
            $ofertaMap = Cache::remember('oferta_map', 300, fn () => PaljetOferta::all()->keyBy('paljet_art_id'));

            $query = CatalogoWeb::where('admin_existencia', true)->where('stock', 0);

            if ($search = $request->query('search')) {
                $query->where('descripcion', 'like', "%{$search}%");
            }
            if ($marca = $request->query('marca')) {
                $query->where('marca_id', (int) $marca);
            }
            if ($familia = $request->query('familia')) {
                $query->where('familia_id', (int) $familia);
            }
            if ($categoriaId = $request->query('categoria_id')) {
                $query->where('categoria_id', (int) $categoriaId);
            }

            $total     = $query->count();
            $articulos = $query->skip($page * $size)->take($size)->get();

            $content = $articulos->map(function ($art) use ($ofertaMap) {
                $oferta = $ofertaMap->get($art->paljet_art_id);
                return [
                    'id'               => $art->paljet_art_id,
                    'codigo'           => $art->codigo,
                    'ean'              => $art->ean,
                    'descripcion'      => $art->descripcion,
                    'desc_cliente'     => $art->desc_cliente,
                    'desc_mod_med'     => $art->desc_mod_med,
                    'marca'            => $art->marca_id ? ['id' => $art->marca_id, 'nombre' => $art->marca_nombre] : null,
                    'familia'          => $art->familia_id ? ['id' => $art->familia_id, 'nombre' => $art->familia_nombre] : null,
                    'categoria'        => $art->categoria_id ? ['id' => $art->categoria_id, 'nombre' => $art->categoria_nombre] : null,
                    'precio'           => $art->precio,
                    'admin_existencia' => $art->admin_existencia,
                    'stock_disponible' => 0,
                    'imagen_url'       => $art->imagen_url,
                    'listas'           => $art->listas_json ?? [],
                    'en_oferta'        => $oferta !== null,
                    'precio_oferta'    => $oferta?->precio_oferta,
                ];
            })->values()->toArray();

            return response()->json([
                'content'       => $content,
                'totalElements' => $total,
                'totalPages'    => (int) ceil($total / $size),
                'number'        => $page,
                'size'          => $size,
            ]);
        }

        // Filtro en_oferta: se resuelve desde catalogo_web + paljet_ofertas (ERP no soporta este filtro)
        if ($request->query('en_oferta') == '1') {
            $ofertas = Cache::remember('oferta_map', 300, fn () => PaljetOferta::all()->keyBy('paljet_art_id'));
            $ids     = $ofertas->keys()->toArray();

            if (empty($ids)) {
                return response()->json([
                    'content'          => [],
                    'totalElements'    => 0,
                    'totalPages'       => 0,
                    'number'           => $page,
                    'size'             => $size,
                ]);
            }

            $query = CatalogoWeb::whereIn('paljet_art_id', $ids);

            if ($search = $request->query('search')) {
                $query->where('descripcion', 'like', "%{$search}%");
            }
            if ($marca = $request->query('marca')) {
                $query->where('marca_id', (int) $marca);
            }
            if ($familia = $request->query('familia')) {
                $query->where('familia_id', (int) $familia);
            }
            if ($categoriaId = $request->query('categoria_id')) {
                $query->where('categoria_id', (int) $categoriaId);
            }

            $total    = $query->count();
            $articulos = $query->skip($page * $size)->take($size)->get();

            $content = $articulos->map(function ($art) use ($ofertas) {
                $oferta = $ofertas->get($art->paljet_art_id);
                return [
                    'id'            => $art->paljet_art_id,
                    'codigo'        => $art->codigo,
                    'ean'           => $art->ean,
                    'descripcion'   => $art->descripcion,
                    'desc_cliente'  => $art->desc_cliente,
                    'desc_mod_med'  => $art->desc_mod_med,
                    'marca'         => $art->marca_id ? ['id' => $art->marca_id, 'nombre' => $art->marca_nombre] : null,
                    'familia'       => $art->familia_id ? ['id' => $art->familia_id, 'nombre' => $art->familia_nombre] : null,
                    'categoria'     => $art->categoria_id ? ['id' => $art->categoria_id, 'nombre' => $art->categoria_nombre] : null,
                    'precio'        => $art->precio,
                    'admin_existencia' => $art->admin_existencia,
                    'stock_disponible' => $art->stock,
                    'imagen_url'    => $art->imagen_url,
                    'listas'        => $art->listas_json ?? [],
                    'en_oferta'     => true,
                    'precio_oferta' => $oferta?->precio_oferta,
                ];
            })->values()->toArray();

            return response()->json([
                'content'       => $content,
                'totalElements' => $total,
                'totalPages'    => (int) ceil($total / $size),
                'number'        => $page,
                'size'          => $size,
            ]);
        }

        // Sin filtros especiales: consulta normal desde catalogo_web
        // publica_web: '1' (default) = solo publicados, '0' = todos los del catálogo local
        $pubWeb  = $request->query('publica_web', '1') === '0' ? null : true;
        $paljet  = app(PaljetService::class);
        $filtros = array_filter([
            'page'        => $page,
            'size'        => $size,
            'descripcion' => $request->query('search'),
            'marca'       => $request->query('marca'),
            'familia'     => $request->query('familia'),
            'categoria'   => $request->query('categoria_id'),
            'solo_activos'=> $request->query('activo', 'true'),
            'publica_web' => $pubWeb ? 'true' : null,
            'include'     => 'listas',
        ], fn($v) => !is_null($v) && $v !== '');

        $data = $paljet->getArticulos($filtros);

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], $data['status'] ?? 500);
        }

        // Enriquecer cada artículo con en_oferta y precio_oferta desde la tabla local
        if (!empty($data['content'])) {
            $ofertaMap = Cache::remember('oferta_map', 300, fn () => PaljetOferta::all()->keyBy('paljet_art_id'));
            $data['content'] = array_map(function ($art) use ($ofertaMap) {
                $oferta = $ofertaMap->get((int) $art['id']);
                $art['en_oferta']     = $oferta !== null;
                $art['precio_oferta'] = $oferta?->precio_oferta;
                return $art;
            }, $data['content']);
        }

        return response()->json($data);
    }

    /**
     * Activar / desactivar el flag en_oferta de un artículo de Paljet.
     * PATCH /admin/productos/{id}/oferta
     */
    public function toggleOferta(Request $request, int $artId)
    {
        $data = $request->validate([
            'en_oferta'     => ['required', 'boolean'],
            'precio_oferta' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['en_oferta']) {
            $oferta = PaljetOferta::firstOrCreate(['paljet_art_id' => $artId]);
            $oferta->precio_oferta = isset($data['precio_oferta']) ? (float) $data['precio_oferta'] : null;
            $oferta->save();
        } else {
            PaljetOferta::where('paljet_art_id', $artId)->delete();
        }

        return response()->json([
            'id'            => $artId,
            'en_oferta'     => (bool) $data['en_oferta'],
            'precio_oferta' => $data['en_oferta'] ? ($data['precio_oferta'] ?? null) : null,
        ]);
    }

    /**
     * Estadísticas de productos desde Paljet
     */
    public function productosStats()
    {
        $paljet = app(PaljetService::class);

        // Total artículos publicados (size=1 para solo traer el count)
        $totalData = $paljet->getArticulos([
            'publica_web'  => 'true',
            'solo_activos' => 'true',
            'size'         => 1,
            'page'         => 0,
        ]);

        $total   = $totalData['totalElements'] ?? 0;
        $activos = $total; // publicados en web = activos

        // Artículos sin stock en dep_id=8
        $sinStockData = $paljet->getArticulosSinStock(0, 1);
        $sinStock     = $sinStockData['totalElements'] ?? 0;

        return response()->json([
            'total'    => $total,
            'activos'  => $activos,
            'sinStock' => $sinStock,
        ], 200);
    }

    // =====================
    // CRUD PRODUCTOS
    // =====================

    public function crearProducto(Request $request)
    {
        $data = $request->validate([
            'nombre'       => ['required', 'string', 'max:200'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'marca_id'     => ['nullable', 'integer', 'exists:marcas,id'],
            'codigo'       => ['nullable', 'string', 'max:60'],
            'descripcion'  => ['nullable', 'string'],
            'precio'       => ['required', 'numeric', 'min:0'],
            'unidad'       => ['nullable', 'string', 'max:30'],
            'activo'       => ['sometimes', 'boolean'],
            'destacado'    => ['sometimes', 'boolean'],
        ]);

        $data['slug'] = Str::slug($data['nombre']);

        // Evitar slug duplicado
        $baseSlug = $data['slug'];
        $counter = 1;
        while (Producto::where('slug', $data['slug'])->exists()) {
            $data['slug'] = $baseSlug . '-' . $counter++;
        }

        $producto = Producto::create($data);

        return response()->json([
            'message'  => 'Producto creado correctamente',
            'producto' => $producto->load(['marca', 'categoria']),
        ], 201);
    }

    public function actualizarProducto(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $data = $request->validate([
            'nombre'       => ['sometimes', 'string', 'max:200'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'marca_id'     => ['nullable', 'integer', 'exists:marcas,id'],
            'codigo'       => ['nullable', 'string', 'max:60'],
            'descripcion'  => ['nullable', 'string'],
            'precio'       => ['sometimes', 'numeric', 'min:0'],
            'unidad'       => ['nullable', 'string', 'max:30'],
            'activo'       => ['sometimes', 'boolean'],
            'destacado'    => ['sometimes', 'boolean'],
        ]);

        if (isset($data['nombre'])) {
            $slug = Str::slug($data['nombre']);
            $baseSlug = $slug;
            $counter = 1;
            while (Producto::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $data['slug'] = $slug;
        }

        $producto->update($data);

        return response()->json([
            'message'  => 'Producto actualizado correctamente',
            'producto' => $producto->load(['marca', 'categoria']),
        ], 200);
    }

    public function eliminarProducto(int $id)
    {
        $producto = Producto::findOrFail($id);
        $producto->update(['activo' => false]);

        return response()->json([
            'message' => 'Producto eliminado correctamente',
        ], 200);
    }

    // =====================
    // ACTUALIZAR CLIENTE
    // =====================

    public function actualizarCliente(Request $request, int $id)
    {
        $cliente = Cliente::findOrFail($id);

        $data = $request->validate([
            'nombre'               => ['sometimes', 'string', 'max:120'],
            'apellido'             => ['sometimes', 'string', 'max:120'],
            'telefono'             => ['sometimes', 'string', 'max:40'],
            'dni'                  => ['sometimes', 'string', 'max:20'],
            'cuit'                 => ['nullable', 'string', 'max:20'],
            'condicion_iva'        => ['nullable', 'string', 'max:80'],
            'activo'               => ['sometimes', 'boolean'],
            'nombre_empresa'       => ['nullable', 'string', 'max:160'],
            'direccion_calle'      => ['nullable', 'string', 'max:160'],
            'direccion_numero'     => ['nullable', 'string', 'max:20'],
            'direccion_piso'       => ['nullable', 'string', 'max:20'],
            'direccion_depto'      => ['nullable', 'string', 'max:20'],
            'direccion_localidad'  => ['nullable', 'string', 'max:80'],
            'direccion_provincia'  => ['nullable', 'string', 'max:80'],
            'direccion_codigo_postal' => ['nullable', 'string', 'max:20'],
        ]);

        $cliente->update($data);

        return response()->json([
            'message' => 'Cliente actualizado correctamente',
            'cliente' => $cliente->fresh()->load('user'),
        ], 200);
    }

    // =====================
    // ESTADÍSTICAS DE CLIENTES
    // =====================

    public function clientesStats()
    {
        $total = Cliente::count();

        $newThisMonth = Cliente::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $active = Cliente::whereHas('pedidos', function ($q) {
            $q->where('created_at', '>=', now()->subDays(90));
        })->count();

        $avgSpentResult = DB::selectOne(
            'SELECT AVG(total_cliente) as avg FROM (
                SELECT SUM(total_final) as total_cliente
                FROM pedidos
                WHERE cliente_id IS NOT NULL
                  AND estado IN (?, ?)
                GROUP BY cliente_id
            ) as por_cliente',
            ['en_proceso', 'entregado']
        );
        $avgSpent = $avgSpentResult->avg ?? 0;

        return response()->json([
            'total'        => $total,
            'newThisMonth' => $newThisMonth,
            'active'       => $active,
            'avgSpent'     => round((float) $avgSpent, 2),
        ]);
    }

    // =====================
    // CONFIGURACION
    // =====================

    private function configDefaults(): array
    {
        return [
            'general' => [
                'siteName'        => 'Ferretería Argentina',
                'contactEmail'    => 'contacto@ferreteria.com',
                'contactPhone'    => '+54 11 1234-5678',
                'siteDescription' => 'Tu ferretería de confianza',
                'currency'        => 'ARS',
                'timezone'        => 'America/Argentina/Buenos_Aires',
            ],
            'store' => [
                'minStock'        => 10,
                'productsPerPage' => 12,
                'shippingCost'    => 1000,
                'freeShippingFrom'=> 50000,
                'enableRetiro'    => true,
                'enableEnvio'     => true,
            ],
        ];
    }

    public function configuracion()
    {
        $defaults = $this->configDefaults();

        $stored = Configuracion::whereIn('clave', ['general', 'store'])
            ->get()
            ->pluck('valor', 'clave');

        $general = isset($stored['general'])
            ? array_merge($defaults['general'], json_decode($stored['general'], true) ?? [])
            : $defaults['general'];

        $store = isset($stored['store'])
            ? array_merge($defaults['store'], json_decode($stored['store'], true) ?? [])
            : $defaults['store'];

        return response()->json([
            'general' => $general,
            'store'   => $store,
        ]);
    }

    public function actualizarConfiguracion(Request $request)
    {
        $data = $request->validate([
            'section'  => ['required', 'string', 'in:general,store'],
            'settings' => ['required', 'array'],
        ]);

        $defaults = $this->configDefaults();
        $section  = $data['section'];

        // Mergear con lo que ya está guardado para no perder otros keys
        $existing = Configuracion::where('clave', $section)->value('valor');
        $current  = $existing ? (json_decode($existing, true) ?? []) : [];
        $merged   = array_merge($defaults[$section], $current, $data['settings']);

        Configuracion::updateOrCreate(
            ['clave' => $section],
            ['valor' => json_encode($merged)]
        );

        return response()->json(['ok' => true]);
    }

    // =====================
    // CRUD SUCURSALES
    // =====================

    public function crearSucursal(Request $request)
    {
        $data = $request->validate([
            'nombre'    => ['required', 'string', 'max:120'],
            'ciudad'    => ['required', 'string', 'max:80'],
            'direccion' => ['required', 'string', 'max:200'],
            'telefono'  => ['nullable', 'string', 'max:40'],
            'activo'    => ['sometimes', 'boolean'],
        ]);

        $sucursal = Sucursal::create($data);

        return response()->json([
            'message'  => 'Sucursal creada correctamente',
            'sucursal' => $sucursal,
        ], 201);
    }

    public function actualizarSucursal(Request $request, int $id)
    {
        $sucursal = Sucursal::findOrFail($id);

        $data = $request->validate([
            'nombre'    => ['sometimes', 'string', 'max:120'],
            'ciudad'    => ['sometimes', 'string', 'max:80'],
            'direccion' => ['sometimes', 'string', 'max:200'],
            'telefono'  => ['nullable', 'string', 'max:40'],
            'activo'    => ['sometimes', 'boolean'],
        ]);

        $sucursal->update($data);

        return response()->json([
            'message'  => 'Sucursal actualizada correctamente',
            'sucursal' => $sucursal->fresh(),
        ], 200);
    }

    public function eliminarSucursal(int $id)
    {
        $sucursal = Sucursal::findOrFail($id);
        $sucursal->update(['activo' => false]);

        return response()->json([
            'message' => 'Sucursal eliminada correctamente',
        ], 200);
    }

}
