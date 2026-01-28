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

            // Datos de facturación y contacto
            'contacto.nombre' => ['required', 'string', 'max:160'],
            'contacto.apellido' => ['required', 'string', 'max:160'],
            'contacto.email' => ['required', 'email', 'max:160'],
            'contacto.telefono' => ['required', 'string', 'max:40'],
            'contacto.condicion_iva' => ['required', 'string', 'max:80'],
            'contacto.dni' => ['nullable', 'string', 'max:20'],
            'contacto.cuit' => ['nullable', 'string', 'max:20'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],

            // Envío (solo si tipo_entrega = envio)
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

            // Extras
            'items.*.extras' => ['nullable', 'array'],
        ]);

        $sucursalId = (int) $data['sucursal_id'];
        $productoIds = collect($data['items'])->pluck('producto_id')->unique()->values()->all();
        $venceEn = now()->addMinutes(20);

        $result = DB::transaction(function () use ($data, $sucursalId, $productoIds, $venceEn) {
            
            // (Validaciones de producto y stock... sin cambios)
            $productos = DB::table('productos')->select('id', 'nombre', 'precio', 'activo')->whereIn('id', $productoIds)->where('activo', true)->get()->keyBy('id');
            if ($productos->count() !== count($productoIds)) {
                abort(422, 'Hay productos inválidos o inactivos.');
            }
            // ... (resto de la lógica de validación de stock)

            // 5) Crear pedido
            $pedidoId = DB::table('pedidos')->insertGetId([
                'cliente_id' => null, // Asumimos checkout de invitado por ahora
                'sucursal_id' => $sucursalId,
                'tipo_entrega' => $data['tipo_entrega'],

                // Guardamos todos los datos de contacto/facturación en el pedido
                'nombre_contacto' => $data['contacto']['nombre'] . ' ' . $data['contacto']['apellido'],
                'email_contacto' => $data['contacto']['email'],
                'telefono_contacto' => $data['contacto']['telefono'],
                'condicion_iva_contacto' => $data['contacto']['condicion_iva'],
                'dni_contacto' => $data['contacto']['dni'] ?? null,
                'cuit_contacto' => $data['contacto']['cuit'] ?? null,

                'estado' => 'pendiente_pago',
                'total_productos' => 0,
                'costo_envio' => 0,
                'total_final' => 0,
                'moneda' => 'ARS',
                'nota_cliente' => $data['nota_cliente'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

             // (Lógica de inserción de items, reservas y envío... sin cambios)
             // ...

            return [
                'ok' => true,
                'pedido_id' => $pedidoId,
                // ... (resto de la respuesta)
            ];
        });

        if (!$result['ok']) {
            return response()->json($result, 409);
        }

        return response()->json($result, 201);
    }
    // (resto de los métodos... sin cambios)
}
