<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Services\ContenedorReservaService;
use App\Models\Pedido;

class PedidoController extends Controller
{
    public function index(Request $request)
    {
        // Obtener el usuario autenticado
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        // Iniciar query base con relaciones
        $query = Pedido::with([
            'items.producto',
            'envio',
            'sucursal',
            'cliente',
            'contenedorReservas',
            'pagos',
            'reservasStock'
        ]);

        // Si NO es admin, filtrar solo sus pedidos
        if ($user->rol !== 'admin') {
            $cliente = $user->cliente;

            if (!$cliente) {
                return response()->json(['error' => 'No hay cliente asociado a este usuario'], 404);
            }

            $query->where('cliente_id', $cliente->id);
        }

        // Si es admin, puede ver todos los pedidos (no se aplica filtro por cliente)

        // Filtros opcionales
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }

        if ($request->has('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }

        // Ordenar por más reciente primero
        $query->orderBy('created_at', 'desc');

        // Paginación
        $perPage = $request->get('per_page', 15);
        $pedidos = $query->paginate($perPage);

        return response()->json($pedidos, 200);
    }

    public function show(int $id)
    {
        // Obtener el usuario autenticado
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        // Iniciar query base
        $query = Pedido::with([
            'items.producto',
            'envio',
            'sucursal',
            'cliente',
            'contenedorReservas',
            'pagos',
            'reservasStock'
        ])->where('id', $id);

        // Si NO es admin, filtrar solo sus pedidos
        if ($user->rol !== 'admin') {
            $cliente = $user->cliente;

            if (!$cliente) {
                return response()->json(['error' => 'No hay cliente asociado a este usuario'], 404);
            }

            $query->where('cliente_id', $cliente->id);
        }

        // Si es admin, puede ver cualquier pedido

        $pedido = $query->first();

        if (!$pedido) {
            return response()->json(['error' => 'Pedido no encontrado o no tienes permiso para verlo'], 404);
        }

        return response()->json($pedido, 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'tipo_entrega' => ['required', 'in:retiro_sucursal,envio'],

            'contacto.nombre' => ['required', 'string', 'max:160'],
            'contacto.apellido' => ['required', 'string', 'max:160'],
            'contacto.email' => ['required', 'email', 'max:160'],
            'contacto.telefono' => ['required', 'string', 'max:40'],
            'contacto.condicion_iva' => ['required', 'string', 'max:80'],
            'contacto.dni' => ['required', 'string', 'max:20'],
            'contacto.cuit' => ['nullable', 'string', 'max:20'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.extras.tipo' => ['nullable', 'in:contenedor'],

            'envio' => ['nullable', 'array'],
            'envio.calle' => ['required_if:tipo_entrega,envio', 'string', 'max:160'],
            'envio.numero' => ['required_if:tipo_entrega,envio', 'string', 'max:20'],
            'envio.piso' => ['nullable', 'string', 'max:20'],
            'envio.depto' => ['nullable', 'string', 'max:20'],
            'envio.ciudad' => ['required_if:tipo_entrega,envio', 'string', 'max:80'],
            'envio.provincia' => ['required_if:tipo_entrega,envio', 'string', 'max:80'],
            'envio.codigo_postal' => ['nullable', 'string', 'max:20'],
            'envio.referencias' => ['nullable', 'string', 'max:255'],

            'nota_cliente' => ['nullable', 'string', 'max:255'],

            'items.*.extras' => ['nullable', 'array'],

            'items.*.extras.fecha_entrega' => ['nullable', 'date'],
            'items.*.extras.localidad' => ['nullable', 'string', 'max:120'],
            'items.*.extras.domicilio' => ['nullable', 'string', 'max:180'],
            'items.*.extras.telefono' => ['nullable', 'string', 'max:40'],

            'items.*.extras.codigo_postal' => ['nullable', 'string', 'max:20'],
            'items.*.extras.cuenta_corriente' => ['nullable', 'boolean'],
            'items.*.extras.comprobante_path' => ['nullable', 'string', 'max:255'],
            'items.*.extras.observaciones' => ['nullable', 'string'],
            'items.*.extras.referencia' => ['nullable', 'string', 'max:255'],
            'items.*.extras.dias_alquiler' => ['nullable', 'integer', 'min:1', 'max:60'],

        ]);

        // Validación de seguridad: Si NO está autenticado y el email ya existe, pedir login
        if (!auth('sanctum')->check()) {
            $emailExiste = DB::table('clientes')
                ->where('email', $data['contacto']['email'])
                ->exists();

            if (!$emailExiste) {
                // También verificar en la tabla users
                $emailExiste = DB::table('users')
                    ->where('email', $data['contacto']['email'])
                    ->exists();
            }

            if ($emailExiste) {
                return response()->json([
                    'error' => 'email_registrado',
                    'message' => 'Este email ya está registrado. Por favor, inicia sesión para continuar con tu compra.',
                    'email' => $data['contacto']['email']
                ], 422);
            }
        }

        $sucursalId = (int) $data['sucursal_id'];
        $venceEn = now()->addMinutes(20);

        // Obtener cliente_id si el usuario está autenticado
        $clienteId = null;
        $user = auth('sanctum')->user();
        if ($user) {
            $cliente = $user->cliente;
            if ($cliente) {
                $clienteId = $cliente->id;
            }
        }

        $items = collect($data['items'])->map(function ($item) {
            $item['extras'] = $item['extras'] ?? [];
            return $item;
        });

        $productoIds = $items->pluck('producto_id')->unique()->values()->all();

        $result = DB::transaction(function () use ($data, $items, $productoIds, $sucursalId, $venceEn, $clienteId) {

            // 1) Productos activos + flag es_contenedor
            $productos = DB::table('productos')
                ->select('id', 'nombre', 'precio', 'activo')
                ->whereIn('id', $productoIds)
                ->where('activo', true)
                ->get()
                ->keyBy('id');

            if ($productos->count() !== count($productoIds)) {
                abort(422, 'Algunos productos no existen o no están activos.');
            }

            // 2) Cantidades por producto (si viene repetido)
            $cantPorProducto = $items
                ->groupBy('producto_id')
                ->map(fn($rows) => (int) $rows->sum('cantidad'));

            // 3) Lock stock_sucursal
            $stocks = DB::table('stock_sucursal')
                ->select('producto_id', 'cantidad')
                ->where('sucursal_id', $sucursalId)
                ->whereIn('producto_id', $productoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_id');

            // 4) Validar stock
            $sinStock = [];
            foreach ($cantPorProducto as $productoId => $cantNecesaria) {
                $disp = (int) ($stocks[$productoId]->cantidad ?? 0);
                if ($disp < $cantNecesaria) $sinStock[] = $productoId;
            }

            if (!empty($sinStock)) {
                $nombres = collect($sinStock)->map(fn($pid) => $productos[$pid]->nombre)->implode(', ');
                abort(409, 'No hay stock suficiente para: ' . $nombres);
            }

            // 5) Crear pedido
            $pedidoId = DB::table('pedidos')->insertGetId([
                'cliente_id' => $clienteId, // Vincula con el cliente autenticado si existe
                'sucursal_id' => $sucursalId,
                'tipo_entrega' => $data['tipo_entrega'],

                'nombre_contacto' => $data['contacto']['nombre'] . ' ' . $data['contacto']['apellido'],
                'email_contacto' => $data['contacto']['email'],
                'telefono_contacto' => $data['contacto']['telefono'],

                'estado' => 'pendiente_pago',
                'total_productos' => 0,
                'costo_envio' => 0,
                'total_final' => 0,
                'moneda' => 'ARS',

                'nota_cliente' => $data['nota_cliente'] ?? null,
                'nota_interna' => null,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6) Items + contenedor_reservas si aplica
            $totalProductos = 0;
            $contenedorService = app(ContenedorReservaService::class);

            foreach ($items as $item) {
                $producto = $productos[$item['producto_id']];
                $cantidad = (int) $item['cantidad'];
                $precioUnitario = (float) $producto->precio;
                $subtotal = $precioUnitario * $cantidad;
                $totalProductos += $subtotal;

                $extras = $item['extras'] ?? [];
                $esContenedor = (($extras['tipo'] ?? null) === 'contenedor');


                // Si es contenedor, validación mínima (tu service vuelve a validar en serio)
                if ($esContenedor) {
                    $v = Validator::make(
                        ['item' => $item],
                        [
                            'item.extras.tipo' => ['required', 'in:contenedor'],
                            'item.extras.fecha_entrega' => ['required', 'date'],
                            'item.extras.localidad' => ['required', 'string', 'max:120'],
                            'item.extras.domicilio' => ['required', 'string', 'max:180'],
                            'item.extras.telefono' => ['required', 'string', 'max:40'],
                            'item.extras.codigo_postal' => ['nullable', 'string', 'max:20'],
                            'item.extras.cuenta_corriente' => ['nullable', 'boolean'],
                            'item.extras.comprobante_path' => ['nullable', 'string', 'max:255'],
                            'item.extras.observaciones' => ['nullable', 'string'],
                            'item.extras.referencia' => ['nullable', 'string', 'max:255'],
                            'item.extras.dias_alquiler' => ['nullable', 'integer', 'min:1', 'max:60'],
                        ]
                    );

                    if ($v->fails()) {
                        throw new ValidationException($v);
                    }
                }



                $pedidoItemId = DB::table('pedido_items')->insertGetId([
                    'pedido_id' => $pedidoId,
                    'producto_id' => (int) $item['producto_id'],

                    'nombre_producto' => $producto->nombre,
                    'precio_unitario' => $precioUnitario,
                    'cantidad' => $cantidad,
                    'subtotal' => $subtotal,

                    'extras' => !empty($extras) ? json_encode($extras) : null,

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Crear/actualizar contenedor_reservas por item
                if ($esContenedor) {
                    $diasAlquiler = (int)($extras['dias_alquiler'] ?? 3);

                    app(ContenedorReservaService::class)->createOrUpdateFromPedidoItemId(
                        pedidoItemId: $pedidoItemId,
                        pedidoId: $pedidoId,
                        productoId: (int)$item['producto_id'],
                        cantidad: $cantidad,
                        extras: $extras,
                        diasAlquiler: $diasAlquiler
                    );
                }
            }

            // 7) Reservas de stock (activa + vence_en) AGREGADAS por producto
            foreach ($cantPorProducto as $productoId => $cantNecesaria) {
                DB::table('reservas_stock')->insert([
                    'pedido_id' => $pedidoId,
                    'producto_id' => (int)$productoId,
                    'sucursal_id' => $sucursalId,
                    'cantidad' => (int)$cantNecesaria,
                    'estado' => 'activa',
                    'vence_en' => $venceEn,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 8) Envío si corresponde
            $costoEnvio = 0;
            if ($data['tipo_entrega'] === 'envio') {
                DB::table('envios')->insert([
                    'pedido_id' => $pedidoId,
                    'calle' => $data['envio']['calle'],
                    'numero' => $data['envio']['numero'],
                    'piso' => $data['envio']['piso'] ?? null,
                    'depto' => $data['envio']['depto'] ?? null,
                    'ciudad' => $data['envio']['ciudad'],
                    'provincia' => $data['envio']['provincia'],
                    'codigo_postal' => $data['envio']['codigo_postal'] ?? null,
                    'referencias' => $data['envio']['referencias'] ?? null,

                    'estado' => 'pendiente',
                    'empresa' => null,
                    'tracking_codigo' => null,
                    'despachado_en' => null,
                    'entregado_en' => null,

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 9) Totales
            DB::table('pedidos')->where('id', $pedidoId)->update([
                'total_productos' => $totalProductos,
                'costo_envio' => $costoEnvio,
                'total_final' => $totalProductos + $costoEnvio,
                'updated_at' => now(),
            ]);

            return [
                'ok' => true,
                'pedido_id' => $pedidoId,
                'vence_en' => $venceEn->toIso8601String(),
            ];
        });

        return response()->json($result, 201);
    }

    public function confirmarPago(int $pedidoId)
    {
        DB::transaction(function () use ($pedidoId) {

            // 1) Lock pedido
            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->lockForUpdate()
                ->first();

            if (!$pedido) abort(404, 'Pedido no encontrado.');

            // Idempotencia: si ya está pagado, no hagas nada
            if ($pedido->estado === 'pagado') {
                return;
            }

            if ($pedido->estado !== 'pendiente_pago') {
                abort(409, 'El pedido no está en estado pendiente_pago.');
            }

            // 2) Traer y lockear reservas activas
            $reservas = DB::table('reservas_stock')
                ->where('pedido_id', $pedidoId)
                ->lockForUpdate()
                ->get();

            if ($reservas->isEmpty()) {
                abort(409, 'El pedido no tiene reservas de stock.');
            }

            // 3) Validar que no estén vencidas / liberadas
            $now = now();
            $hayInvalida = $reservas->contains(function ($r) use ($now) {
                return $r->estado !== 'activa' || $r->vence_en < $now;
            });

            if ($hayInvalida) {
                // Marcamos pedido fallido si querés
                DB::table('pedidos')->where('id', $pedidoId)->update([
                    'estado' => 'fallido',
                    'updated_at' => now(),
                ]);

                // Y marcamos reservas vencidas si corresponde
                DB::table('reservas_stock')
                    ->where('pedido_id', $pedidoId)
                    ->where('estado', 'activa')
                    ->where('vence_en', '<', $now)
                    ->update([
                        'estado' => 'vencida',
                        'updated_at' => now(),
                    ]);

                abort(409, 'La reserva no está activa o ya venció.');
            }

            // 4) Lock stock_sucursal de todos los productos involucrados
            $productoIds = $reservas->pluck('producto_id')->unique()->values()->all();

            $stocks = DB::table('stock_sucursal')
                ->where('sucursal_id', (int)$pedido->sucursal_id)
                ->whereIn('producto_id', $productoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_id');

            // 5) Descontar stock (segunda validación, por seguridad)
            foreach ($reservas as $r) {
                $disp = (int) ($stocks[$r->producto_id]->cantidad ?? 0);
                if ($disp < (int)$r->cantidad) {
                    DB::table('pedidos')->where('id', $pedidoId)->update([
                        'estado' => 'fallido',
                        'updated_at' => now(),
                    ]);
                    abort(409, 'Stock insuficiente al confirmar pago (condición de carrera).');
                }
            }

            foreach ($reservas as $r) {
                DB::table('stock_sucursal')
                    ->where('sucursal_id', (int)$pedido->sucursal_id)
                    ->where('producto_id', (int)$r->producto_id)
                    ->update([
                        'cantidad' => DB::raw('cantidad - ' . (int)$r->cantidad),
                        'updated_at' => now(),
                    ]);
            }

            // 6) Confirmar reservas + pedido pagado
            DB::table('reservas_stock')
                ->where('pedido_id', $pedidoId)
                ->where('estado', 'activa')
                ->update([
                    'estado' => 'confirmada',
                    'updated_at' => now(),
                ]);

            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado' => 'pagado',
                'updated_at' => now(),
            ]);

            // 7) Confirmar reservas de contenedor (si existen)
            app(ContenedorReservaService::class)->confirmarPorPedido($pedidoId);
        });

        return response()->json(['ok' => true], 200);
    }

    public function subirComprobante(Request $request, int $pedidoId)
    {
        $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ]);

        $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();

        if (!$pedido) {
            return response()->json(['error' => 'Pedido no encontrado'], 404);
        }

        if ($pedido->estado !== 'pendiente_pago') {
            return response()->json(['error' => 'El pedido no está en estado pendiente_pago'], 409);
        }

        $path = $request->file('comprobante')->store("comprobantes/pedido-{$pedidoId}", 'public');

        DB::table('pedidos')->where('id', $pedidoId)->update([
            'comprobante_path' => $path,
            'updated_at'       => now(),
        ]);

        return response()->json([
            'message' => 'Comprobante subido correctamente',
            'path'    => $path,
        ], 200);
    }

    public function update(Request $request, int $pedidoId)
    {
        $data = $request->validate([
            'estado'       => ['sometimes', 'string', 'in:pendiente_pago,pagado,preparando,enviado,entregado,cancelado,fallido'],
            'nota_interna' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();

        if (!$pedido) {
            return response()->json(['error' => 'Pedido no encontrado'], 404);
        }

        $update = ['updated_at' => now()];

        if (isset($data['estado'])) {
            $update['estado'] = $data['estado'];
        }

        if (array_key_exists('nota_interna', $data)) {
            $update['nota_interna'] = $data['nota_interna'];
        }

        DB::table('pedidos')->where('id', $pedidoId)->update($update);

        return response()->json([
            'message' => 'Pedido actualizado correctamente',
            'pedido'  => DB::table('pedidos')->where('id', $pedidoId)->first(),
        ], 200);
    }

    public function cancelar(int $pedidoId)
    {
        DB::transaction(function () use ($pedidoId) {

            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->lockForUpdate()
                ->first();

            if (!$pedido) abort(404, 'Pedido no encontrado.');

            // Si ya está pagado, NO lo canceles acá (sería devolución)
            if ($pedido->estado === 'pagado') {
                abort(409, 'El pedido está pagado. Esto debería ser una devolución, no cancelación.');
            }

            // Permitir cancelar si está pendiente_pago o borrador
            if (!in_array($pedido->estado, ['pendiente_pago', 'borrador'], true)) {
                abort(409, 'El pedido no puede cancelarse en este estado.');
            }

            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado' => 'cancelado',
                'updated_at' => now(),
            ]);

            // Liberar reservas activas
            DB::table('reservas_stock')
                ->where('pedido_id', $pedidoId)
                ->where('estado', 'activa')
                ->update([
                    'estado' => 'liberada',
                    'updated_at' => now(),
                ]);

            // Cancelar contenedores (si existieran)
            app(ContenedorReservaService::class)->cancelarPorPedido($pedidoId);
        });

        return response()->json(['ok' => true], 200);
    }
}
