<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PedidoService
{
    public function __construct(private ContenedorReservaService $contenedorReserva) {}

    /**
     * Ejecuta la transacción completa de creación de pedido.
     *
     * Retorna ['ok' => true, 'pedido_id' => int, 'access_token' => string, 'vence_en' => string]
     * Lanza excepción (abort) ante errores de negocio (stock, validación).
     */
    public function crearPedido(
        array      $data,
        Collection $items,
        Collection $itemsLocales,
        array      $productoIds,
        int        $sucursalId,
        Carbon     $venceEn,
        ?int       $clienteId
    ): array {
        return DB::transaction(function () use ($data, $items, $itemsLocales, $productoIds, $sucursalId, $venceEn, $clienteId) {

            // 1) Traer productos LOCALES
            $productos = collect();
            if (!empty($productoIds)) {
                $productos = DB::table('productos')
                    ->select('id', 'nombre', 'precio', 'activo', 'es_contenedor')
                    ->whereIn('id', $productoIds)
                    ->where('activo', true)
                    ->get()
                    ->keyBy('id');

                if ($productos->count() !== count($productoIds)) {
                    abort(422, 'Algunos productos no existen o no están activos.');
                }
            }

            // 2) Separar items locales: normales vs contenedor
            $itemsNormales   = collect();
            $itemsContenedor = collect();

            foreach ($itemsLocales as $item) {
                $prod   = $productos[$item['producto_id']];
                $extras = $item['extras'] ?? [];
                $esContenedor = (($extras['tipo'] ?? null) === 'contenedor') || (bool)($prod->es_contenedor ?? false);

                if ($esContenedor) $itemsContenedor->push($item);
                else $itemsNormales->push($item);
            }

            // 3) Cantidades AGREGADAS SOLO para productos normales
            $cantPorProductoNormal = $itemsNormales
                ->groupBy('producto_id')
                ->map(fn($rows) => (int) $rows->sum('cantidad'));

            // 4) Lock stock_sucursal SOLO para productos normales
            $stocks = collect();
            if ($cantPorProductoNormal->isNotEmpty()) {
                $stocks = DB::table('stock_sucursal')
                    ->select('producto_id', 'cantidad')
                    ->where('sucursal_id', $sucursalId)
                    ->whereIn('producto_id', $cantPorProductoNormal->keys()->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('producto_id');

                // 5) Validar stock SOLO normales
                $sinStock = [];
                foreach ($cantPorProductoNormal as $productoId => $cantNecesaria) {
                    $disp = (int) ($stocks[$productoId]->cantidad ?? 0);
                    if ($disp < $cantNecesaria) $sinStock[] = $productoId;
                }

                if (!empty($sinStock)) {
                    $nombres = collect($sinStock)->map(fn($pid) => $productos[$pid]->nombre)->implode(', ');
                    abort(409, 'No hay stock suficiente para: ' . $nombres);
                }
            }

            // 6) Crear pedido
            $accessToken = (string) Str::uuid();

            $pedidoId = DB::table('pedidos')->insertGetId([
                'cliente_id'  => $clienteId,
                'sucursal_id' => $sucursalId,
                'tipo_entrega' => $data['tipo_entrega'],

                'nombre_contacto'        => $data['contacto']['nombre'] . ' ' . $data['contacto']['apellido'],
                'email_contacto'         => $data['contacto']['email'],
                'telefono_contacto'      => $data['contacto']['telefono'],
                'dni_contacto'           => $data['contacto']['dni']            ?? null,
                'cuit_contacto'          => $data['contacto']['cuit']           ?? null,
                'condicion_iva_contacto' => $data['contacto']['condicion_iva'] ?? null,

                'estado'          => 'pendiente_pago',
                'total_productos' => 0,
                'costo_envio'     => 0,
                'total_final'     => 0,
                'moneda'          => 'ARS',
                'access_token'    => $accessToken,

                'nota_cliente' => $data['nota_cliente'] ?? null,
                'nota_interna' => null,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7) Insertar items + (si contenedor) crear contenedor_reservas
            $totalProductos = 0;

            foreach ($items as $item) {
                $cantidad = (int) $item['cantidad'];
                $extras   = $item['extras'] ?? [];

                if (!empty($item['paljet_art_id'])) {
                    $precioUnitario  = (float) ($item['precio_unitario'] ?? 0);
                    $subtotal        = $precioUnitario * $cantidad;
                    $totalProductos += $subtotal;

                    DB::table('pedido_items')->insert([
                        'pedido_id'       => $pedidoId,
                        'producto_id'     => null,
                        'paljet_art_id'   => (int) $item['paljet_art_id'],
                        'nombre_producto' => $item['nombre_producto'] ?? 'Artículo',
                        'precio_unitario' => $precioUnitario,
                        'cantidad'        => $cantidad,
                        'subtotal'        => $subtotal,
                        'es_contenedor'   => false,
                        'extras'          => null,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                    continue;
                }

                $producto       = $productos[$item['producto_id']];
                $precioUnitario = (float) $producto->precio;
                $subtotal       = $precioUnitario * $cantidad;
                $totalProductos += $subtotal;

                $porExtras    = (($extras['tipo'] ?? null) === 'contenedor');
                $porFlag      = (bool) ($producto->es_contenedor ?? false);
                $esContenedor = $porExtras || $porFlag;

                if ($esContenedor) {
                    $v = Validator::make(
                        ['item' => $item],
                        [
                            'item.extras.tipo'             => ['required', 'in:contenedor'],
                            'item.extras.fecha_entrega'    => ['required', 'date'],
                            'item.extras.localidad'        => ['required', 'string', 'max:120'],
                            'item.extras.domicilio'        => ['required', 'string', 'max:180'],
                            'item.extras.telefono'         => ['required', 'string', 'max:40'],
                            'item.extras.codigo_postal'    => ['nullable', 'string', 'max:20'],
                            'item.extras.cuenta_corriente' => ['nullable', 'boolean'],
                            'item.extras.comprobante_path' => ['nullable', 'string', 'max:255'],
                            'item.extras.observaciones'    => ['nullable', 'string'],
                            'item.extras.referencia'       => ['nullable', 'string', 'max:255'],
                            'item.extras.dias_alquiler'    => ['nullable', 'integer', 'min:1', 'max:60'],
                        ]
                    );

                    if ($v->fails()) {
                        throw new ValidationException($v);
                    }
                }

                $pedidoItemId = DB::table('pedido_items')->insertGetId([
                    'pedido_id'       => $pedidoId,
                    'producto_id'     => (int) $item['producto_id'],
                    'paljet_art_id'   => null,
                    'nombre_producto' => $producto->nombre,
                    'precio_unitario' => $precioUnitario,
                    'cantidad'        => $cantidad,
                    'subtotal'        => $subtotal,
                    'es_contenedor'   => $esContenedor,
                    'extras'          => !empty($extras) ? json_encode($extras) : null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                if ($esContenedor) {
                    $diasAlquiler = (int)($extras['dias_alquiler'] ?? 3);

                    $this->contenedorReserva->createOrUpdateFromPedidoItemId(
                        pedidoItemId: $pedidoItemId,
                        pedidoId:     $pedidoId,
                        productoId:   (int)$item['producto_id'],
                        cantidad:     $cantidad,
                        extras:       $extras,
                        diasAlquiler: $diasAlquiler
                    );
                }
            }

            // 8) Reservas de stock
            foreach ($cantPorProductoNormal as $productoId => $cantNecesaria) {
                DB::table('reservas_stock')->insert([
                    'pedido_id'   => $pedidoId,
                    'producto_id' => (int)$productoId,
                    'sucursal_id' => $sucursalId,
                    'cantidad'    => (int)$cantNecesaria,
                    'estado'      => 'activa',
                    'vence_en'    => $venceEn,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            // 9) Envío
            if ($data['tipo_entrega'] === 'envio') {
                DB::table('envios')->insert([
                    'pedido_id'      => $pedidoId,
                    'calle'          => $data['envio']['calle'],
                    'numero'         => $data['envio']['numero'],
                    'piso'           => $data['envio']['piso']           ?? null,
                    'depto'          => $data['envio']['depto']          ?? null,
                    'ciudad'         => $data['envio']['ciudad'],
                    'provincia'      => $data['envio']['provincia'],
                    'codigo_postal'  => $data['envio']['codigo_postal']  ?? null,
                    'referencias'    => $data['envio']['referencias']    ?? null,
                    'estado'         => 'pendiente',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            // 10) Totales
            DB::table('pedidos')->where('id', $pedidoId)->update([
                'total_productos' => $totalProductos,
                'costo_envio'     => 0,
                'total_final'     => $totalProductos,
                'updated_at'      => now(),
            ]);

            return [
                'ok'          => true,
                'pedido_id'   => $pedidoId,
                'access_token' => $accessToken,
                'vence_en'    => $venceEn->toIso8601String(),
            ];
        });
    }
}
