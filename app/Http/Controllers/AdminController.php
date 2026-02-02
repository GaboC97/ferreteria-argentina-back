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

        $perPage = $request->get('per_page', 15);
        $clientes = $query->paginate($perPage);

        return response()->json($clientes, 200);
    }

    /**
     * Listar todos los productos con stock (admin)
     */
    public function productos(Request $request)
    {
        $query = Producto::with(['marca', 'categoria']);

        // Filtros opcionales
        if ($request->has('activo')) {
            $query->where('activo', $request->activo);
        }

        if ($request->has('marca_id')) {
            $query->where('marca_id', $request->marca_id);
        }

        if ($request->has('categoria_id')) {
            $query->where('categoria_id', $request->categoria_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('descripcion', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $productos = $query->paginate($perPage);

        return response()->json($productos, 200);
    }

    /**
     * Estadísticas de productos
     */
    public function productosStats()
    {
        $totalProductos = DB::table('productos')->count();
        $productosActivos = DB::table('productos')->where('activo', true)->count();
        $productosSinStock = DB::table('productos')
            ->leftJoin('stock_sucursal', 'productos.id', '=', 'stock_sucursal.producto_id')
            ->select('productos.id')
            ->groupBy('productos.id')
            ->havingRaw('COALESCE(SUM(stock_sucursal.cantidad), 0) = 0')
            ->count();

        return response()->json([
            'total' => $totalProductos,
            'activos' => $productosActivos,
            'sinStock' => $productosSinStock,
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
