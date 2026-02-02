<?php

namespace App\Http\Controllers;

use App\Mail\PedidoConfirmadoAdmin;
use App\Mail\PedidoConfirmadoCliente;
use App\Mail\ContenedorReservaConfirmadaAdmin;
use App\Mail\ContenedorReservaConfirmadaCliente;
use App\Models\ContenedorReserva;
use App\Models\Producto;
use App\Models\Pedido;
use App\Models\Pago;
use App\Models\ReservaStock;
use Illuminate\Support\Facades\Mail;
use App\Services\ContenedorReservaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MercadoPagoController extends Controller
{
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

        $items = DB::table('pedido_items as pi')
            ->join('productos as p', 'p.id', '=', 'pi.producto_id')
            ->where('pi.pedido_id', $pedidoId)
            ->select('pi.producto_id', 'pi.cantidad', 'pi.precio_unitario', 'p.nombre')
            ->get();

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
            'title' => $it->nombre,
            'quantity' => (int)$it->cantidad,
            'unit_price' => (float)$it->precio_unitario,
            'currency_id' => 'ARS',
        ])->values()->all();

        foreach ($items as $it) {
            $price = (float)$it->precio_unitario;
            if ($price <= 0) {
                return response()->json([
                    'message' => 'Producto sin precio válido para Mercado Pago',
                    'producto_id' => $it->producto_id,
                    'nombre' => $it->nombre ?? null,
                    'precio_unitario' => $it->precio_unitario,
                ], 422);
            }
        }

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
                'estado' => 'rechazado',
                'mp_status' => 'preference_error',
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
        $idempotencyKey = null;

        // 1) Transacción para validar estado y gestionar idempotencia
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

            // ✅ Si el pedido ya está pagado (por webhook), cortamos acá
            if ($pedido->estado === 'pagado') {
                DB::commit();
                return response()->json([
                    'ok' => true,
                    'pago_id' => $pagoId,
                    'pedido_id' => (int) $pedido->id,
                    'status' => $pago->mp_status ?? null,
                    'status_detail' => $pago->mp_status_detail ?? null,
                    'note' => 'pedido_already_pagado',
                ]);
            }

            if ($pedido->estado !== 'pendiente_pago') {
                DB::rollBack();
                return response()->json(['message' => 'El pedido no está en estado pendiente_pago'], 409);
            }

            // ✅ Si ya está APROBADO en este pago, no dejamos pagar de nuevo
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

            // Si es REINTENTO (ya tenía mp_payment_id pero no approved), generamos NUEVA key
            if (!empty($pago->mp_payment_id) && $pago->mp_status !== 'approved') {
                $idempotencyKey = (string) Str::uuid();

                DB::table('pagos')->where('id', $pagoId)->update([
                    'mp_idempotency_key' => $idempotencyKey,
                    'updated_at' => now(),
                ]);
            }
            // Primer intento
            elseif (!$idempotencyKey) {
                $idempotencyKey = (string) Str::uuid();

                DB::table('pagos')->where('id', $pagoId)->update([
                    'mp_idempotency_key' => $idempotencyKey,
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 2) Preparar llamada a API
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

        // 3) Llamada a Mercado Pago
        $resp = Http::withToken($mpToken)
            ->withHeaders([
                'X-Idempotency-Key' => $idempotencyKey,
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
                'estado' => 'rechazado',
                'mp_status' => 'error',
                'mp_raw_json' => $resp->body(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Error procesando el pago con el banco',
                'detail' => $resp->json(),
            ], 502);
        }

        $mp = $resp->json();

        // 4) Guardar respuesta de MP (esto sí)
        DB::table('pagos')->where('id', $pagoId)->update([
            'mp_payment_id' => data_get($mp, 'id'),
            'mp_status' => data_get($mp, 'status'),
            'mp_status_detail' => data_get($mp, 'status_detail'),
            'mp_raw_json' => json_encode($mp),
            'estado' => in_array(data_get($mp, 'status'), ['approved'], true) ? 'aprobado'
                : (in_array(data_get($mp, 'status'), ['rejected', 'cancelled'], true) ? 'rechazado' : 'pendiente'),
            'updated_at' => now(),
        ]);

        // ✅ IMPORTANTÍSIMO:
        // NO confirmamos pedido/stock/envíos acá.
        // Eso lo hace SOLO el Webhook (idempotente y con locks), evitando mails duplicados.
        return response()->json([
            'ok' => true,
            'pago_id' => $pagoId,
            'pedido_id' => (int) $pedido->id,
            'mp_payment_id' => data_get($mp, 'id'),
            'status' => data_get($mp, 'status'),
            'status_detail' => data_get($mp, 'status_detail'),
            'note' => 'business_confirmed_by_webhook',
        ]);
    }


    public function estado(Request $request)
    {
        $data = $request->validate([
            'pedido_id' => ['required', 'integer'],
        ]);

        $pedidoId = (int) $data['pedido_id'];

        // 1. Buscar pedido local
        $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();
        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado'], 404);
        }

        // 2. Si YA está pagado en nuestra BD, retornamos éxito rápido (Fast Path)
        if ($pedido->estado === 'pagado') {
            return response()->json([
                'ok' => true,
                'pedido_id' => $pedidoId,
                'status' => 'approved',
                'pago_estado' => 'aprobado',
                'note' => 'already_paid_local'
            ]);
        }

        // 3. Si está pendiente, CONSULTAMOS A MERCADO PAGO EN TIEMPO REAL
        try {
            $mpToken = config('services.mercadopago.access_token');

            // Buscamos pagos en MP que coincidan con este pedido (external_reference)
            // Ordenamos por fecha para obtener el último intento
            $resp = Http::withToken($mpToken)
                ->acceptJson()
                ->get('https://api.mercadopago.com/v1/payments/search', [
                    'external_reference' => (string) $pedidoId,
                    'sort' => 'date_created',
                    'criteria' => 'desc',
                    'limit' => 1
                ]);

            // Si MP no responde o no tiene datos, devolvemos lo que tenga la BD local
            if (!$resp->successful() || empty($resp->json()['results'])) {
                // Fallback a lo que tenga la base de datos
                return $this->responderEstadoLocal($pedidoId);
            }

            $pagoMP = $resp->json()['results'][0]; // El pago más reciente en MP
            $statusMP = $pagoMP['status']; // approved, rejected, pending...

            // 4. EL MOMENTO DE LA VERDAD:
            // Si MP dice "approved" pero nosotros seguimos "pendiente_pago",
            // FORZAMOS la actualización AHORA MISMO.
            if ($statusMP === 'approved' && $pedido->estado !== 'pagado') {
                $this->sincronizarPagoConMP($pedidoId, $pagoMP);

                return response()->json([
                    'ok' => true,
                    'pedido_id' => $pedidoId,
                    'status' => 'approved',
                    'pago_estado' => 'aprobado',
                    'note' => 'synced_live'
                ]);
            }

            // Si no está aprobado, devolvemos el estado real de MP
            return response()->json([
                'ok' => true,
                'pedido_id' => $pedidoId,
                'status' => $statusMP,
                'pago_estado' => $this->mapStatus($statusMP),
            ]);
        } catch (\Exception $e) {
            Log::error("Error consultando estado MP en vivo: " . $e->getMessage());
            // Si falla la conexión, mostramos lo que hay en local para no romper el front
            return $this->responderEstadoLocal($pedidoId);
        }
    }
    private function responderEstadoLocal($pedidoId)
    {
        $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();
        $pago = DB::table('pagos')->where('pedido_id', $pedidoId)->orderByDesc('id')->first();

        return response()->json([
            'ok' => true,
            'pedido_id' => $pedidoId,
            'status' => $pago->mp_status ?? 'pending',
            'pago_estado' => $pago->estado ?? 'pendiente'
        ]);
    }

    private function mapStatus($status)
    {
        return match ($status) {
            'approved' => 'aprobado',
            'pending', 'in_process' => 'pendiente',
            'rejected' => 'rechazado',
            'cancelled' => 'cancelado',
            default => 'pendiente'
        };
    }

    /**
     * Esta función hace el trabajo sucio: Actualiza DB, Stock y Pedido
     * basándose en la respuesta "approved" que acabamos de recibir de MP.
     */
private function sincronizarPagoConMP($pedidoId, $dataMP) 
    {
        DB::transaction(function () use ($pedidoId, $dataMP) {
            // Lock para evitar colisión con Webhook
            $pedido = Pedido::lockForUpdate()->find($pedidoId);
            
            // Si ya estaba pagado, no hacemos nada (idempotencia)
            if ($pedido->estado === 'pagado') return;

            // 1. Actualizar Pago
            $pago = Pago::firstOrCreate(
                ['pedido_id' => $pedidoId],
                ['estado' => 'pendiente', 'moneda' => 'ARS']
            );
            $pago->update([
                'mp_payment_id' => $dataMP['id'],
                'mp_status' => $dataMP['status'],
                'mp_status_detail' => $dataMP['status_detail'],
                'estado' => 'aprobado',
                'aprobado_en' => now(),
                'monto' => $dataMP['transaction_amount'] ?? $pago->monto
            ]);

            // 2. Descontar Stock
            $reservas = ReservaStock::where('pedido_id', $pedidoId)->where('estado', 'activa')->lockForUpdate()->get();
            foreach ($reservas as $rs) {
                DB::table('stock_sucursal')
                    ->where('sucursal_id', $rs->sucursal_id)
                    ->where('producto_id', $rs->producto_id)
                    ->decrement('cantidad', $rs->cantidad);
                $rs->update(['estado' => 'confirmada']);
            }

            // 3. Confirmar Contenedores
            try {
                 if (class_exists(ContenedorReservaService::class)) {
                     app(ContenedorReservaService::class)->confirmarPorPedido($pedidoId);
                 }
            } catch (\Exception $e) {
                Log::error("Error servicio contenedor sync: " . $e->getMessage());
            }

            // 4. Marcar Pedido Pagado
            $pedido->estado = 'pagado';
            $pedido->save();

            // ✅ 5. ENVIAR MAILS (Copiado del WebhookController)
            // Lógica para diferenciar compra normal vs contenedor
            $reservasContenedor = ContenedorReserva::where('pedido_id', $pedido->id)->get();

            if ($reservasContenedor->isNotEmpty()) {
                foreach ($reservasContenedor as $rc) {
                    $this->enviarMailsReservaContenedorPagada($pedido, $rc);
                }
            } else {
                $this->enviarMailsPedidoPagado($pedido);
            }
        });
    }

    private function enviarMailsPedidoPagado(Pedido $pedido): void
    {
        $adminEmail  = config('mail.ferreteria.notif_email') ?? 'gabrielcarbone97@gmail.com'; 
        $clienteEmail = $pedido->email_contacto;

        // 1. Cliente
        if ($clienteEmail) {
            $tomado = Pedido::where('id', $pedido->id)
                ->whereNull('email_cliente_enviado_at')
                ->update(['email_cliente_enviado_at' => now()]);

            if ($tomado) {
                try {
                    Mail::to($clienteEmail)->send(new PedidoConfirmadoCliente($pedido));
                } catch (\Throwable $e) {
                    Log::error("Fallo mail cliente: " . $e->getMessage());
                    Pedido::where('id', $pedido->id)->update(['email_cliente_enviado_at' => null]);
                }
            }
        }

        // 2. Admin
        if ($adminEmail) {
            $tomado = Pedido::where('id', $pedido->id)
                ->whereNull('email_admin_enviado_at')
                ->update(['email_admin_enviado_at' => now()]);

            if ($tomado) {
                try {
                    Mail::to($adminEmail)->send(new PedidoConfirmadoAdmin($pedido));
                } catch (\Throwable $e) {
                    Log::error("Fallo mail admin: " . $e->getMessage());
                    Pedido::where('id', $pedido->id)->update(['email_admin_enviado_at' => null]);
                }
            }
        }
    }

    private function enviarMailsReservaContenedorPagada(Pedido $pedido, ContenedorReserva $reserva): void
    {
        $adminEmail  = config('mail.ferreteria.notif_email') ?? 'gabrielcarbone97@gmail.com';
        $clienteEmail = $pedido->email_contacto;
        $productoNombre = Producto::find($reserva->producto_id)?->nombre;

        // Cliente
        if ($clienteEmail) {
            $tomado = ContenedorReserva::where('id', $reserva->id)
                ->whereNull('email_enviado_at')
                ->update(['email_enviado_at' => now()]);
            if ($tomado) {
                try {
                    Mail::to($clienteEmail)->send(new ContenedorReservaConfirmadaCliente($pedido, $reserva, $productoNombre));
                } catch (\Throwable $e) {
                    ContenedorReserva::where('id', $reserva->id)->update(['email_enviado_at' => null]);
                }
            }
        }

        // Admin
        if ($adminEmail) {
            $tomado = ContenedorReserva::where('id', $reserva->id)
                 ->whereNull('email_admin_enviado_at')
                 ->update(['email_admin_enviado_at' => now()]);
            if ($tomado) {
                try {
                    Mail::to($adminEmail)->send(new ContenedorReservaConfirmadaAdmin($pedido, $reserva, $productoNombre));
                } catch (\Throwable $e) {
                    ContenedorReserva::where('id', $reserva->id)->update(['email_admin_enviado_at' => null]);
                }
            }
        }
    }
}

