<?php

namespace App\Http\Controllers;

use App\Services\ContenedorReservaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MercadoPagoController extends Controller
{
    // POST /api/pagos/mercadopago/preferencia
// POST /api/pagos/mercadopago/preferencia
public function crearPreferencia(Request $request)
{
    $data = $request->validate([
        'pedido_id' => ['required', 'integer'],
    ]);

    $pedidoId = (int) $data['pedido_id'];

    $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();
    if (!$pedido) return response()->json(['message' => 'Pedido no encontrado'], 404);

    if ($pedido->estado !== 'pendiente_pago') {
        return response()->json(['message' => 'El pedido no está en estado pendiente_pago'], 409);
    }

    $items = DB::table('pedido_items')->where('pedido_id', $pedidoId)->get();
    if ($items->isEmpty()) return response()->json(['message' => 'El pedido no tiene items'], 409);

    // ✅ Evitar pagar vacío (reservas o contenedores)
    $reservasStockActivas = DB::table('reservas_stock')
        ->where('pedido_id', $pedidoId)
        ->where('estado', 'activa')
        ->count();

    $contenedores = DB::table('contenedor_reservas')
        ->where('pedido_id', $pedidoId)
        ->count();

    if ($reservasStockActivas === 0 && $contenedores === 0) {
        return response()->json(['message' => 'El pedido no tiene reservas activas ni contenedores.'], 409);
    }

    $medioMp = DB::table('medios_pago')->where('codigo', 'mercadopago')->first();
    if (!$medioMp) return response()->json(['message' => 'Medio de pago Mercado Pago no existe'], 500);

    $mpToken = config('services.mercadopago.access_token');
    if (!$mpToken) return response()->json(['message' => 'MP_ACCESS_TOKEN no configurado'], 500);

    // ✅ Reutilizar preferencia existente (evita duplicados)
    $pagoExistente = DB::table('pagos')
        ->where('pedido_id', $pedidoId)
        ->whereNotNull('mp_preference_id')
        ->orderByDesc('id')
        ->first();

    if ($pagoExistente && $pagoExistente->mp_raw_json) {
        $raw = json_decode($pagoExistente->mp_raw_json, true);
        $initPoint = $raw['init_point'] ?? null;
        $sandboxInitPoint = $raw['sandbox_init_point'] ?? null;

        if ($initPoint || $sandboxInitPoint) {
            return response()->json([
                'ok' => true,
                'pedido_id' => $pedidoId,
                'pago_id' => $pagoExistente->id,
                'preference_id' => $pagoExistente->mp_preference_id,
                'init_point' => $initPoint,
                'sandbox_init_point' => $sandboxInitPoint,
            ]);
        }
    }

    // ✅ Crear registro de pago (nuevo)
    $pagoId = DB::table('pagos')->insertGetId([
        'pedido_id' => $pedidoId,
        'medio_pago_id' => $medioMp->id,
        'estado' => 'iniciado',
        'monto' => (float) $pedido->total_final,
        'moneda' => $pedido->moneda ?? 'ARS',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mpItems = $items->map(fn($it) => [
        'title' => $it->nombre_producto,
        'quantity' => (int) $it->cantidad,
        'unit_price' => (float) $it->precio_unitario,
        'currency_id' => config('mercadopago.currency_id', 'ARS'),
    ])->values()->all();

    $payloadMp = [
        'items' => $mpItems,
        'external_reference' => (string) $pedidoId,
        'back_urls' => config('mercadopago.back_urls'),
        'notification_url' => config('mercadopago.webhook_url'),
        'auto_return' => 'approved',
        'metadata' => [
            'pedido_id' => $pedidoId,
            'pago_id' => $pagoId,
        ],
    ];

    $resp = Http::withToken($mpToken)
        ->acceptJson()
        ->post('https://api.mercadopago.com/checkout/preferences', $payloadMp);

    if (!$resp->successful()) {
        Log::error('MP preference error', [
            'status' => $resp->status(),
            'body' => $resp->body(),
            'pedido_id' => $pedidoId,
            'pago_id' => $pagoId,
        ]);

        DB::table('pagos')->where('id', $pagoId)->update([
            'estado' => 'error',
            'mp_raw_json' => $resp->body(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Error creando preferencia MP'], 502);
    }

    $pref = $resp->json();

    DB::table('pagos')->where('id', $pagoId)->update([
        'mp_preference_id' => $pref['id'] ?? null,
        'mp_status' => 'preference_created',
        'mp_raw_json' => json_encode($pref),
        'updated_at' => now(),
    ]);

    return response()->json([
        'ok' => true,
        'pedido_id' => $pedidoId,
        'pago_id' => $pagoId,
        'preference_id' => $pref['id'] ?? null,
        'init_point' => $pref['init_point'] ?? null,
        'sandbox_init_point' => $pref['sandbox_init_point'] ?? null,
    ]);
}



    public function pagar(Request $request)
    {
        $data = $request->validate([
            'pago_id' => ['required', 'integer'],
            'token' => ['required', 'string'],
            'payment_method_id' => ['required', 'string'],
            'installments' => ['required', 'integer', 'min:1'],
            'payer.email' => ['required', 'email'],
            'payer.identification.type' => ['nullable', 'string'],
            'payer.identification.number' => ['nullable', 'string'],
        ]);

        $pagoId = (int) $data['pago_id'];

        $mpToken = config('services.mercadopago.access_token');
        if (!$mpToken) return response()->json(['message' => 'MP_ACCESS_TOKEN no configurado'], 500);

        $pago = null;
        $pedido = null;
        $idempotencyKey = null; // Inicializamos variable

        // 1. Transacción para validar estado y generar Idempotency Key
        DB::beginTransaction();
        try {
            $pago = DB::table('pagos')->lockForUpdate()->where('id', $pagoId)->first();
            if (!$pago) {
                DB::rollBack();
                return response()->json(['message' => 'Pago no encontrado'], 404);
            }

            $pedido = DB::table('pedidos')->lockForUpdate()->where('id', $pago->pedido_id)->first();
            if (!$pedido) {
                DB::rollBack();
                return response()->json(['message' => 'Pedido no encontrado'], 404);
            }

            if ($pedido->estado !== 'pendiente_pago') {
                DB::rollBack();
                return response()->json(['message' => 'El pedido no está en estado pendiente_pago'], 409);
            }

            // ✅ Si ya está APROBADO, no dejamos pagar de nuevo.
            if (!empty($pago->mp_payment_id) && $pago->mp_status === 'approved') {
                DB::commit();
                return response()->json([
                    'ok' => true,
                    'pago_id' => $pagoId,
                    'pedido_id' => (int) $pedido->id,
                    'mp_payment_id' => $pago->mp_payment_id,
                    'status' => $pago->mp_status,
                    'status_detail' => $pago->mp_status_detail,
                    'note' => 'payment_already_approved',
                ]);
            }

            // ✅ LÓGICA DE IDEMPOTENCIA
            $idempotencyKey = $pago->mp_idempotency_key;

            // Si es un REINTENTO (tenía ID pero falló), generamos NUEVA key
            if (!empty($pago->mp_payment_id) && $pago->mp_status !== 'approved') {
                $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

                DB::table('pagos')->where('id', $pagoId)->update([
                    'mp_idempotency_key' => $idempotencyKey,
                    'updated_at' => now(),
                ]);
            }
            // Si es el PRIMER intento absoluto
            elseif (!$idempotencyKey) {
                $idempotencyKey = (string) \Illuminate\Support\Str::uuid();
                DB::table('pagos')->where('id', $pagoId)->update([
                    'mp_idempotency_key' => $idempotencyKey,
                    'updated_at' => now(),
                ]);
            }

            DB::commit(); // Cerramos transacción inicial
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 2. Preparar llamada a API
        $amount = (float) $pago->monto;
        if ($amount <= 0) return response()->json(['message' => 'Monto inválido'], 422);

        $payload = [
            'transaction_amount' => $amount,
            'token' => $data['token'],
            'description' => "Pedido #{$pedido->id}",
            'installments' => (int) $data['installments'],
            'payment_method_id' => $data['payment_method_id'],
            'payer' => [
                'email' => $data['payer']['email'],
                'identification' => [
                    'type' => data_get($data, 'payer.identification.type'),
                    'number' => data_get($data, 'payer.identification.number'),
                ],
            ],
            'external_reference' => (string) $pedido->id,
            'metadata' => [
                'pedido_id' => (int) $pedido->id,
                'pago_id' => $pagoId,
            ],
            'notification_url' => config('mercadopago.webhook_url'),
        ];

        // 3. Llamada a Mercado Pago
        $resp = Http::withToken($mpToken)
            ->withHeaders([
                'X-Idempotency-Key' => $idempotencyKey, // Usamos la key gestionada arriba
            ])
            ->acceptJson()
            ->post('https://api.mercadopago.com/v1/payments', $payload);

        if (!$resp->successful()) {
            Log::error('MP payment error', [
                'status' => $resp->status(),
                'body' => $resp->body(),
                'pago_id' => $pagoId,
            ]);

            DB::table('pagos')->where('id', $pagoId)->update([
                'estado' => 'error',
                'mp_raw_json' => $resp->body(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Error procesando el pago con el banco',
                'detail' => $resp->json(),
            ], 502); // 502 Bad Gateway es adecuado para error de servicio externo
        }

        $mp = $resp->json();

        // 4. Guardar respuesta de MP
        DB::table('pagos')->where('id', $pagoId)->update([
            'mp_payment_id' => data_get($mp, 'id'),
            'mp_status' => data_get($mp, 'status'),
            'mp_status_detail' => data_get($mp, 'status_detail'),
            'mp_raw_json' => json_encode($mp),
            'estado' => in_array(data_get($mp, 'status'), ['approved'], true) ? 'aprobado'
                : (in_array(data_get($mp, 'status'), ['rejected', 'cancelled'], true) ? 'rechazado' : 'pendiente'),
            'updated_at' => now(),
        ]);

        // 5. 🔥 ACTUALIZACIÓN DE NEGOCIO (Pedido, Reservas, Stock)
        $mpStatus = data_get($mp, 'status');

        if ($mpStatus === 'approved') {

            // A. Actualizar estado del PEDIDO
            DB::table('pedidos')->where('id', $pedido->id)->update([
                'estado' => 'pagado',
                'updated_at' => now(),
            ]);

            // B. Confirmar logística de contenedor
            try {
                app(\App\Services\ContenedorReservaService::class)->confirmarPorPedido($pedido->id);
            } catch (\Exception $e) {
                Log::error("Error confirmando reserva contenedor: " . $e->getMessage());
            }

            // C. Descontar stock real
            $reservas = DB::table('reservas_stock')
                ->where('pedido_id', $pedido->id)
                ->where('estado', 'activa')
                ->get();

            foreach ($reservas as $r) {
                DB::table('stock_sucursal')
                    ->where('producto_id', $r->producto_id)
                    ->where('sucursal_id', $r->sucursal_id)
                    ->decrement('cantidad', $r->cantidad);

                DB::table('reservas_stock')->where('id', $r->id)->update([
                    'estado' => 'confirmada',
                    'updated_at' => now()
                ]);
            }
        }

        return response()->json([
            'ok' => true,
            'pago_id' => $pagoId,
            'pedido_id' => (int) $pedido->id,
            'mp_payment_id' => data_get($mp, 'id'),
            'status' => data_get($mp, 'status'),
            'status_detail' => data_get($mp, 'status_detail'),
        ]);
    }

public function estado(Request $request)
{
    $data = $request->validate([
        'pedido_id' => ['required', 'integer'],
    ]);

    $pedidoId = (int) $data['pedido_id'];

    $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();
    if (!$pedido) {
        return response()->json(['message' => 'Pedido no encontrado'], 404);
    }

    // Traemos el último pago asociado a ese pedido
    $pago = DB::table('pagos')
        ->where('pedido_id', $pedidoId)
        ->orderByDesc('id')
        ->first();

    if (!$pago) {
        return response()->json([
            'ok' => true,
            'pedido_id' => $pedidoId,
            'pedido_estado' => $pedido->estado,
            'pago' => null,
            'status' => null,
            'note' => 'no_pago_yet',
        ]);
    }

    return response()->json([
        'ok' => true,
        'pedido_id' => $pedidoId,
        'pedido_estado' => $pedido->estado,
        'pago_id' => $pago->id,
        'status' => $pago->mp_status ?? null,          // approved | pending | rejected | etc
        'status_detail' => $pago->mp_status_detail ?? null,
        'mp_payment_id' => $pago->mp_payment_id ?? null,
        'pago_estado' => $pago->estado ?? null,         // aprobado | pendiente | rechazado | cancelado
    ]);
}

}
