<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ContenedorReservaService;

class PedidoController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'tipo_entrega' => ['required', 'in:retiro_sucursal,envio'],

            'contacto.nombre' => ['required', 'string', 'max:160'],
            'contacto.email' => ['required', 'email', 'max:160'],
            'contacto.telefono' => ['nullable', 'string', 'max:40'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],

            // envío (solo si tipo_entrega = envio)
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

            // Extras por item (contenedor u otros servicios)
            'items.*.extras' => ['nullable', 'array'],
            'items.*.extras.fecha_entrega' => ['nullable', 'date'],
            'items.*.extras.localidad' => ['nullable', 'string', 'max:120'],
            'items.*.extras.domicilio' => ['nullable', 'string', 'max:180'],
            'items.*.extras.codigo_postal' => ['nullable', 'string', 'max:20'],
            'items.*.extras.telefono' => ['nullable', 'string', 'max:40'],
            'items.*.extras.cuenta_corriente' => ['nullable', 'boolean'],
            'items.*.extras.comprobante_path' => ['nullable', 'string', 'max:255'],
        ]);

        $sucursalId = (int) $data['sucursal_id'];

        // IDs únicos de productos del request
        $productoIds = collect($data['items'])
            ->pluck('producto_id')
            ->map(fn($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        $venceEn = now()->addMinutes(20);

        $result = DB::transaction(function () use ($data, $sucursalId, $productoIds, $venceEn) {

            // 1) Traer productos activos
            $productos = DB::table('productos')
                ->select('id', 'nombre', 'precio', 'activo', 'categoria_id')
                ->whereIn('id', $productoIds)
                ->where('activo', true)
                ->get()
                ->keyBy('id');

            if ($productos->count() !== count($productoIds)) {
                abort(422, 'Hay productos inválidos o inactivos.');
            }

            // 2) Detectar categoría contenedor por slug (más robusto que nombre)
            $contenedorCategoriaId = (int) DB::table('categorias')
                ->where('slug', 'contenedor')
                ->value('id');

            if (!$contenedorCategoriaId) {
                abort(500, 'No existe la categoría "contenedor" (slug=contenedor).');
            }

            $isContenedorProducto = function ($producto) use ($contenedorCategoriaId): bool {
                return (int) $producto->categoria_id === $contenedorCategoriaId;
            };

            // 3) Separar items: contenedores (NO stock) vs normales (SÍ stock)
            $itemsContenedor = [];       // cada uno con extras (NO consolidar)
            $qtyNormales = [];           // consolidados por producto_id

            foreach ($data['items'] as $it) {
                $pid = (int) $it['producto_id'];
                $cant = (int) $it['cantidad'];
                $p = $productos[$pid];

                if ($isContenedorProducto($p)) {
                    $itemsContenedor[] = [
                        'producto_id' => $pid,
                        'cantidad' => $cant,
                        'extras' => $it['extras'] ?? null,
                    ];
                } else {
                    $qtyNormales[$pid] = ($qtyNormales[$pid] ?? 0) + $cant;
                }
            }

            // 4) Validar stock SOLO para productos normales
            if (!empty($qtyNormales)) {
                $normalIds = array_keys($qtyNormales);

                $stockRows = DB::table('stock_sucursal')
                    ->where('sucursal_id', $sucursalId)
                    ->whereIn('producto_id', $normalIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('producto_id');

                $reservasActivas = DB::table('reservas_stock')
                    ->select('producto_id', DB::raw('SUM(cantidad) as reservada'))
                    ->where('sucursal_id', $sucursalId)
                    ->whereIn('producto_id', $normalIds)
                    ->where('estado', 'activa')
                    ->groupBy('producto_id')
                    ->get()
                    ->keyBy('producto_id');

                $errores = [];
                foreach ($qtyNormales as $productoId => $cantSolicitada) {
                    $stock = (int) ($stockRows[$productoId]->cantidad ?? 0);
                    $reservada = (int) ($reservasActivas[$productoId]->reservada ?? 0);
                    $disponible = $stock - $reservada;

                    if ($disponible < $cantSolicitada) {
                        $errores[] = [
                            'producto_id' => (int) $productoId,
                            'solicitado' => (int) $cantSolicitada,
                            'disponible' => (int) $disponible,
                        ];
                    }
                }

                if (!empty($errores)) {
                    return [
                        'ok' => false,
                        'message' => 'Stock insuficiente para uno o más productos.',
                        'errores' => $errores,
                    ];
                }
            }

            // 5) Crear pedido
            $pedidoId = DB::table('pedidos')->insertGetId([
                'cliente_id' => null,
                'sucursal_id' => $sucursalId,
                'tipo_entrega' => $data['tipo_entrega'],

                'nombre_contacto' => $data['contacto']['nombre'],
                'email_contacto' => $data['contacto']['email'],
                'telefono_contacto' => $data['contacto']['telefono'] ?? null,

                'estado' => 'pendiente_pago',
                'total_productos' => 0,
                'costo_envio' => 0,
                'total_final' => 0,
                'moneda' => 'ARS',

                'nota_cliente' => $data['nota_cliente'] ?? null,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6) Insertar items y calcular total_productos
            //    - Contenedores: uno por request (sin consolidar)
            //    - Normales: consolidados por producto
            $totalProductos = 0;

            // A) Insert contenedores + crear contenedor_reservas
            foreach ($itemsContenedor as $it) {
                $pid = (int) $it['producto_id'];
                $cant = (int) $it['cantidad'];
                $p = $productos[$pid];

                $subtotal = (float) $p->precio * $cant;
                $totalProductos += $subtotal;

                $extras = $it['extras'] ?? null;

                $pedidoItemId = DB::table('pedido_items')->insertGetId([
                    'pedido_id' => $pedidoId,
                    'producto_id' => $pid,
                    'nombre_producto' => $p->nombre,
                    'precio_unitario' => $p->precio,
                    'cantidad' => $cant,
                    'subtotal' => $subtotal,
                    'extras' => $extras ? json_encode($extras) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Crear reserva operativa (sin stock)
                // Si aún no tenés Service, podés dejar este insert directo:
                DB::table('contenedor_reservas')->insert([
                    'pedido_item_id'   => $pedidoItemId,
                    'pedido_id'        => $pedidoId,
                    'producto_id'      => $pid,

                    'fecha_entrega'    => data_get($extras, 'fecha_entrega'),
                    'fecha_retiro'     => data_get($extras, 'fecha_entrega')
                        ? \Carbon\Carbon::parse(data_get($extras, 'fecha_entrega'))->addDays(3)->toDateString()
                        : null,

                    'localidad'        => data_get($extras, 'localidad'),
                    'domicilio'        => data_get($extras, 'domicilio'),
                    'codigo_postal'    => data_get($extras, 'codigo_postal'),
                    'telefono'         => data_get($extras, 'telefono'),

                    'cantidad'         => $cant,
                    'cuenta_corriente' => (int) data_get($extras, 'cuenta_corriente', 0),
                    'comprobante_path' => data_get($extras, 'comprobante_path'),

                    'estado'           => 'pendiente',
                    'observaciones'    => null,
                    'fecha_retiro_real' => null,

                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            // B) Insert productos normales consolidados + crear reservas_stock
            if (!empty($qtyNormales)) {
                $pedidoItemsNormales = [];

                foreach ($qtyNormales as $pid => $cantTotal) {
                    $p = $productos[(int) $pid];

                    $subtotal = (float) $p->precio * (int)$cantTotal;
                    $totalProductos += $subtotal;

                    $pedidoItemsNormales[] = [
                        'pedido_id' => $pedidoId,
                        'producto_id' => (int) $pid,
                        'nombre_producto' => $p->nombre,
                        'precio_unitario' => $p->precio,
                        'cantidad' => (int) $cantTotal,
                        'subtotal' => $subtotal,
                        'extras' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('pedido_items')->insert($pedidoItemsNormales);

                // Reservas de stock SOLO normales
                $reservas = [];
                foreach ($qtyNormales as $pid => $cantTotal) {
                    $reservas[] = [
                        'pedido_id' => $pedidoId,
                        'producto_id' => (int) $pid,
                        'sucursal_id' => $sucursalId,
                        'cantidad' => (int) $cantTotal,
                        'estado' => 'activa',
                        'vence_en' => $venceEn,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('reservas_stock')->insert($reservas);
            }

            // 7) Envío
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
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $costoEnvio = 0; // TODO
            }

            $totalFinal = $totalProductos + $costoEnvio;

            // 8) Actualizar totales
            DB::table('pedidos')->where('id', $pedidoId)->update([
                'total_productos' => $totalProductos,
                'costo_envio' => $costoEnvio,
                'total_final' => $totalFinal,
                'updated_at' => now(),
            ]);

            return [
                'ok' => true,
                'pedido_id' => $pedidoId,
                'total_productos' => $totalProductos,
                'costo_envio' => $costoEnvio,
                'total_final' => $totalFinal,
                'vence_reserva_en' => $venceEn->toDateTimeString(),
            ];
        });

        if (!$result['ok']) {
            return response()->json($result, 409);
        }

        return response()->json($result, 201);
    }


    public function show($id)
    {
        $pedido = DB::table('pedidos')->where('id', $id)->first();
        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado'], 404);
        }

        $items = DB::table('pedido_items')->where('pedido_id', $id)->get();
        $envio = DB::table('envios')->where('pedido_id', $id)->first();

        return response()->json([
            'pedido' => $pedido,
            'items' => $items,
            'envio' => $envio,
        ]);
    }
}
