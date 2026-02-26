<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Pedido;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Configuracion;
use App\Models\PaljetOferta;
use App\Services\PaljetService;

class AdminController extends Controller
{
    /**
     * Dashboard principal con todas las estadísticas
     */
    public function dashboard()
    {
        // Obtener fecha de inicio del mes actual
        $mesActual = now()->startOfMonth();
        $mesAnterior = now()->subMonth()->startOfMonth();
        $finMesAnterior = now()->subMonth()->endOfMonth();

        // 1. Ingresos totales del mes actual
        $totalRevenue = DB::table('pedidos')
            ->where('estado', 'pagado')
            ->where('created_at', '>=', $mesActual)
            ->sum('total_final');

        // Ingresos del mes anterior para comparación
        $totalRevenueAnterior = DB::table('pedidos')
            ->where('estado', 'pagado')
            ->whereBetween('created_at', [$mesAnterior, $finMesAnterior])
            ->sum('total_final');

        $revenueTrend = $this->calculateTrend($totalRevenue, $totalRevenueAnterior);

        // 2. Cantidad de pedidos del mes
        $ordersCount = DB::table('pedidos')
            ->where('created_at', '>=', $mesActual)
            ->count();

        $ordersCountAnterior = DB::table('pedidos')
            ->whereBetween('created_at', [$mesAnterior, $finMesAnterior])
            ->count();

        $ordersTrend = $this->calculateTrend($ordersCount, $ordersCountAnterior);

        // 3. Nuevos clientes del mes
        $newCustomers = DB::table('clientes')
            ->where('created_at', '>=', $mesActual)
            ->count();

        $newCustomersAnterior = DB::table('clientes')
            ->whereBetween('created_at', [$mesAnterior, $finMesAnterior])
            ->count();

        $customersTrend = $this->calculateTrend($newCustomers, $newCustomersAnterior);

        // 4. Pedidos pendientes de envío
        $pendingOrders = DB::table('pedidos')
            ->where('estado', 'pagado')
            ->whereIn('tipo_entrega', ['envio'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('envios')
                    ->whereColumn('envios.pedido_id', 'pedidos.id')
                    ->where('envios.estado', 'entregado');
            })
            ->count();

        // 5. Últimos pedidos
        $recentOrders = Pedido::with(['cliente', 'sucursal'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($pedido) {
                return [
                    'id' => $pedido->id,
                    'customer' => $pedido->nombre_contacto ?? $pedido->cliente?->nombre ?? 'Sin nombre',
                    'date' => $pedido->created_at,
                    'status' => $this->translateStatus($pedido->estado),
                    'total' => (float) $pedido->total_final,
                    'tipo_entrega' => $pedido->tipo_entrega,
                    'sucursal' => $pedido->sucursal?->nombre ?? 'N/A',
                ];
            });

        // 6. Productos más vendidos del mes
        $topProducts = DB::table('pedido_items')
            ->join('pedidos', 'pedido_items.pedido_id', '=', 'pedidos.id')
            ->join('productos', 'pedido_items.producto_id', '=', 'productos.id')
            ->select(
                'productos.id',
                'productos.nombre',
                DB::raw('SUM(pedido_items.cantidad) as total_sales'),
                DB::raw('COUNT(DISTINCT pedido_items.pedido_id) as orders_count')
            )
            ->where('pedidos.created_at', '>=', $mesActual)
            ->where('pedidos.estado', 'pagado')
            ->groupBy('productos.id', 'productos.nombre')
            ->orderBy('total_sales', 'desc')
            ->limit(5)
            ->get();

        // Calcular porcentajes para productos
        $maxSales = $topProducts->max('total_sales') ?? 1;
        $topProductsFormatted = $topProducts->map(function ($product) use ($maxSales) {
            return [
                'name' => $product->nombre,
                'sales' => $product->total_sales,
                'ordersCount' => $product->orders_count,
                'percentage' => ($product->total_sales / $maxSales) * 100,
            ];
        });

        return response()->json([
            'stats' => [
                'totalRevenue' => (float) $totalRevenue,
                'revenueTrend' => $revenueTrend,
                'ordersCount' => $ordersCount,
                'ordersTrend' => $ordersTrend,
                'newCustomers' => $newCustomers,
                'customersTrend' => $customersTrend,
                'pendingOrders' => $pendingOrders,
            ],
            'recentOrders' => $recentOrders,
            'topProducts' => $topProductsFormatted,
        ], 200);
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
        $paljet = app(PaljetService::class);

        // Paljet usa page base-0; el frontend admin puede enviar page base-1 o base-0
        $pageRaw = (int) $request->query('page', 1);
        $page    = $pageRaw > 0 ? $pageRaw - 1 : 0; // normalizar a base-0

        $filtros = array_filter([
            'page'        => $page,
            'size'        => min((int) $request->query('per_page', 20), 100),
            'descripcion' => $request->query('search'),
            'marca'       => $request->query('marca'),
            'familia'     => $request->query('familia'),
            'categoria'   => $request->query('categoria_id'),
            'solo_activos'=> $request->query('activo', 'true'),
            'publica_web' => 'true',
            'include'     => 'listas',
        ], fn($v) => !is_null($v) && $v !== '');

        $data = $paljet->getArticulos($filtros);

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], $data['status'] ?? 500);
        }

        // Enriquecer cada artículo con en_oferta y precio_oferta desde la tabla local
        if (!empty($data['content'])) {
            $ofertaMap = PaljetOferta::all()->keyBy('paljet_art_id');
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
    // CONFIGURACION
    // =====================

    public function configuracion()
    {
        $configs = Configuracion::all()->pluck('valor', 'clave');

        return response()->json(['data' => $configs], 200);
    }

    public function actualizarConfiguracion(Request $request)
    {
        $data = $request->validate([
            'configs'          => ['required', 'array'],
            'configs.*.clave'  => ['required', 'string', 'max:100'],
            'configs.*.valor'  => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data['configs'] as $item) {
            Configuracion::updateOrCreate(
                ['clave' => $item['clave']],
                ['valor' => $item['valor'] ?? '']
            );
        }

        $configs = Configuracion::all()->pluck('valor', 'clave');

        return response()->json([
            'message' => 'Configuración actualizada correctamente',
            'data'    => $configs,
        ], 200);
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

    /**
     * Calcular tendencia (porcentaje de cambio)
     */
    private function calculateTrend($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Traducir estados de pedidos
     */
    private function translateStatus($status)
    {
        $statuses = [
            'pendiente_pago' => 'Pendiente',
            'pagado' => 'Completado',
            'enviado' => 'Enviado',
            'entregado' => 'Entregado',
            'cancelado' => 'Cancelado',
            'fallido' => 'Fallido',
        ];

        return $statuses[$status] ?? $status;
    }
}
