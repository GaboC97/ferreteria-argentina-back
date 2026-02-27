<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\ContenedorReservaService;
use App\Services\PagoService;
use App\Services\PaljetService;
use App\Services\PedidoService;
use App\Services\PedidoValidationService;
use Illuminate\Support\Facades\Mail;
use App\Mail\PedidoCreadoMail;
use App\Mail\PagoAprobadoMail;
use App\Models\Pedido;
use App\Models\Pago;

class PedidoController extends Controller
{
    public function __construct(
        private PedidoValidationService $pedidoValidator,
        private PedidoService           $pedidoService,
        private PaljetService           $paljetService,
        private PagoService             $pagoService,
    ) {}

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

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) $request->get('per_page', 15), 100);
        $pedidos = $query->paginate($perPage);

        return response()->json($pedidos, 200);
    }

    public function show(int $id)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        $query = Pedido::with([
            'items.producto',
            'envio',
            'sucursal',
            'cliente',
            'contenedorReservas',
            'pagos',
            'reservasStock'
        ])->where('id', $id);

        if ($user->rol !== 'admin') {
            $cliente = $user->cliente;

            if (!$cliente) {
                return response()->json(['error' => 'No hay cliente asociado a este usuario'], 404);
            }

            $query->where('cliente_id', $cliente->id);
        }

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
            'contacto.cuit' => ['required', 'digits:11'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.paljet_art_id'    => ['nullable', 'integer'],
            'items.*.nombre_producto'  => ['nullable', 'string', 'max:180'],
            'items.*.precio_unitario'  => ['nullable', 'numeric', 'min:0'],
            'items.*.producto_id'      => ['nullable', 'integer', 'exists:productos,id'],
            'items.*.cantidad'         => ['required', 'integer', 'min:1'],
            'items.*.extras'           => ['nullable', 'array'],
            'items.*.extras.tipo'      => ['nullable', 'in:contenedor'],

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

        // Seguridad: si NO está autenticado y el email ya existe, pedir login
        if (!auth('sanctum')->check()) {
            $emailError = $this->pedidoValidator->checkEmailRegistrado($data['contacto']['email']);
            if ($emailError) {
                return response()->json($emailError['payload'], $emailError['httpStatus']);
            }
        }

        $sucursalId = (int) $data['sucursal_id'];
        $venceEn    = now()->addMinutes(20);

        $clienteId = null;
        $user = auth('sanctum')->user();
        if ($user && $user->cliente) {
            $clienteId = $user->cliente->id;
        }

        $items = collect($data['items'])->map(function ($item) {
            $item['extras'] = $item['extras'] ?? [];
            return $item;
        });

        // === PASO 1: Validar que haya al menos un item válido ===
        $itemsPaljet  = $items->filter(fn($i) => !empty($i['paljet_art_id']));
        $itemsLocales = $items->filter(fn($i) =>  empty($i['paljet_art_id']) && !empty($i['producto_id']));

        if ($itemsPaljet->isEmpty() && $itemsLocales->isEmpty()) {
            return response()->json(['message' => 'El pedido no tiene items válidos.'], 422);
        }

        // === PASO 2: Validar stock y precios contra Paljet ANTES de crear nada ===
        if ($itemsPaljet->isNotEmpty()) {
            $paljetResult = $this->pedidoValidator->validarItemsPaljet($itemsPaljet, $items);

            if (!$paljetResult['ok']) {
                return response()->json($paljetResult['payload'], $paljetResult['httpStatus']);
            }

            $items        = $paljetResult['items'];
            $itemsPaljet  = $items->filter(fn($i) => !empty($i['paljet_art_id']));
            $itemsLocales = $items->filter(fn($i) => empty($i['paljet_art_id']) && !empty($i['producto_id']));
        }

        $productoIds = $itemsLocales->pluck('producto_id')->unique()->values()->all();

        $result = $this->pedidoService->crearPedido(
            $data, $items, $itemsLocales, $productoIds, $sucursalId, $venceEn, $clienteId
        );

        // ==========================================================
        // MAILS (fuera de transaction) - BLINDADOS CONTRA RATELIMIT
        // ==========================================================
        try {
            $pedidoModel = Pedido::with(['items', 'envio', 'sucursal'])
                ->find($result['pedido_id']);

            if ($pedidoModel) {
                $emailCliente = $pedidoModel->email_contacto;
                $emailInterno = env('FERRETERIA_PEDIDOS_EMAIL') ?: config('mail.ferreteria.notif_email');

                if (!empty($emailCliente)) {
                    try {
                        Mail::mailer('pedidos')
                            ->to($emailCliente)
                            ->send(new \App\Mail\PedidoCreadoMail($pedidoModel, false));
                    } catch (\Throwable $e) {
                        Log::warning("Falla Mail PedidoCreado Cliente #{$result['pedido_id']}: " . $e->getMessage());
                    }
                }

                if (!empty($emailInterno)) {
                    try {
                        Mail::mailer('pedidos')
                            ->to($emailInterno)
                            ->send(new \App\Mail\PedidoCreadoMail($pedidoModel, true));
                    } catch (\Throwable $e) {
                        Log::warning("Falla Mail PedidoCreado Interno #{$result['pedido_id']}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Error general en sección de mails store', [
                'pedido_id' => $result['pedido_id'] ?? null,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json($result, 201);
    }

    public function confirmarPago(int $pedidoId)
    {
        $pedidoForMail = null;

        DB::transaction(function () use ($pedidoId, &$pedidoForMail) {

            // 1) Lock pedido
            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->lockForUpdate()
                ->first();

            if (!$pedido) abort(404, 'Pedido no encontrado.');

            // Idempotencia
            if ($pedido->estado === 'pagado') {
                app(ContenedorReservaService::class)->confirmarPorPedido($pedidoId);
                $pedidoForMail = $pedido;
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

            // 3) Si hay reservas, validar vigencia
            if ($reservas->isNotEmpty()) {
                $now = now();

                $hayInvalida = $reservas->contains(function ($r) use ($now) {
                    return $r->estado !== 'activa' || $r->vence_en < $now;
                });

                if ($hayInvalida) {
                    DB::table('pedidos')->where('id', $pedidoId)->update([
                        'estado'     => 'fallido',
                        'updated_at' => now(),
                    ]);

                    DB::table('reservas_stock')
                        ->where('pedido_id', $pedidoId)
                        ->where('estado', 'activa')
                        ->where('vence_en', '<', $now)
                        ->update([
                            'estado'     => 'vencida',
                            'updated_at' => now(),
                        ]);

                    abort(409, 'La reserva no está activa o ya venció.');
                }

                // 4) Lock stock_sucursal
                $productoIds = $reservas->pluck('producto_id')->unique()->values()->all();

                $stocks = DB::table('stock_sucursal')
                    ->where('sucursal_id', (int)$pedido->sucursal_id)
                    ->whereIn('producto_id', $productoIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('producto_id');

                // 5) Validar stock + descontar
                foreach ($reservas as $r) {
                    $disp = (int) ($stocks[$r->producto_id]->cantidad ?? 0);
                    if ($disp < (int)$r->cantidad) {
                        DB::table('pedidos')->where('id', $pedidoId)->update([
                            'estado'     => 'fallido',
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
                            'cantidad'   => DB::raw('cantidad - ' . (int)$r->cantidad),
                            'updated_at' => now(),
                        ]);
                }

                // 6) Confirmar reservas
                DB::table('reservas_stock')
                    ->where('pedido_id', $pedidoId)
                    ->where('estado', 'activa')
                    ->update([
                        'estado'     => 'confirmada',
                        'updated_at' => now(),
                    ]);
            }

            // 7) Marcar pedido pagado
            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado'     => 'pagado',
                'updated_at' => now(),
            ]);

            // 8) Confirmar contenedor
            app(ContenedorReservaService::class)->confirmarPorPedido($pedidoId);

            $pedidoForMail = $pedido;
        });

        // --- PALJET: registrar pedido web (fuera de la transacción) ---
        $this->paljetService->registrarPedidoConfirmado($pedidoId);

        // --- MAILS FUERA DE LA TRANSACCIÓN ---
        try {
            $pedidoModel = Pedido::with(['items', 'envio', 'sucursal'])->find($pedidoId);

            if ($pedidoModel) {
                $emailCliente = $pedidoModel->email_contacto;
                $emailInterno = env('FERRETERIA_PAGOS_EMAIL') ?: env('FERRETERIA_NOTIF_EMAIL');

                if ($emailCliente) {
                    try {
                        Mail::mailer('pagos')->to($emailCliente)->send(new PagoAprobadoMail($pedidoModel));
                    } catch (\Throwable $e) {
                        Log::warning("Falla Mail PagoAprobado Cliente #{$pedidoId}: " . $e->getMessage());
                    }
                }

                if ($emailInterno) {
                    try {
                        Mail::mailer('pagos')->to($emailInterno)->send(new PagoAprobadoMail($pedidoModel, true));
                    } catch (\Throwable $e) {
                        Log::warning("Falla Mail PagoAprobado Interno #{$pedidoId}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Error general en sección de mails pago aprobado', [
                'pedido_id' => $pedidoId,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }

    public function subirComprobante(Request $request, int $pedidoId)
    {
        $request->validate([
            'comprobante'  => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
            'access_token' => ['nullable', 'string', 'uuid'],
        ]);

        $pedido = Pedido::findAuthorizedOrFail($pedidoId, $request);

        if ($pedido->estado !== 'pendiente_pago') {
            return response()->json(['error' => 'El pedido no está en estado pendiente_pago'], 409);
        }

        $path = $request->file('comprobante')->store("comprobantes/pedido-{$pedidoId}", 'local');

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

            if ($pedido->estado === 'pagado') {
                abort(409, 'El pedido está pagado. Esto debería ser una devolución, no cancelación.');
            }

            if (!in_array($pedido->estado, ['pendiente_pago', 'borrador'], true)) {
                abort(409, 'El pedido no puede cancelarse en este estado.');
            }

            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado'     => 'cancelado',
                'updated_at' => now(),
            ]);

            DB::table('reservas_stock')
                ->where('pedido_id', $pedidoId)
                ->where('estado', 'activa')
                ->update([
                    'estado'     => 'liberada',
                    'updated_at' => now(),
                ]);

            app(ContenedorReservaService::class)->cancelarPorPedido($pedidoId);
        });

        return response()->json(['ok' => true], 200);
    }

    public function devolver(Request $request, int $pedidoId)
    {
        $data = $request->validate([
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $refundResult = DB::transaction(function () use ($pedidoId, $data) {

            // 1) Lock pedido
            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->lockForUpdate()
                ->first();

            if (!$pedido) abort(404, 'Pedido no encontrado.');

            // 2) Permitir devolver si está en estado devolvible
            $estadosDevolvibles = ['pagado', 'preparando', 'enviado', 'entregado', 'devolucion_solicitada'];
            if (!in_array($pedido->estado, $estadosDevolvibles, true)) {
                abort(409, 'Solo se puede devolver un pedido en estado: ' . implode(', ', $estadosDevolvibles) . '.');
            }

            // 3) Refund en MercadoPago (si el pago fue por MP)
            $refund = ['mp_refund' => false, 'detalle' => null];

            $pago = Pago::where('pedido_id', $pedidoId)
                ->where('estado', 'aprobado')
                ->whereNotNull('mp_payment_id')
                ->lockForUpdate()
                ->first();

            if ($pago && $pago->mp_payment_id) {
                $refund = $this->pagoService->procesarRefundMP($pago);
            }

            // 4) Traer reservas confirmadas de productos
            $reservas = DB::table('reservas_stock')
                ->where('pedido_id', $pedidoId)
                ->where('estado', 'confirmada')
                ->lockForUpdate()
                ->get();

            // 5) Si hay reservas de productos, reponer stock
            if ($reservas->isNotEmpty()) {
                $productoIds = $reservas->pluck('producto_id')->unique()->values()->all();

                $stocks = DB::table('stock_sucursal')
                    ->where('sucursal_id', (int)$pedido->sucursal_id)
                    ->whereIn('producto_id', $productoIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('producto_id');

                foreach ($reservas as $r) {
                    if (!isset($stocks[$r->producto_id])) {
                        DB::table('stock_sucursal')->insert([
                            'sucursal_id' => (int)$pedido->sucursal_id,
                            'producto_id' => (int)$r->producto_id,
                            'cantidad'    => (int)$r->cantidad,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                        continue;
                    }

                    DB::table('stock_sucursal')
                        ->where('sucursal_id', (int)$pedido->sucursal_id)
                        ->where('producto_id', (int)$r->producto_id)
                        ->update([
                            'cantidad'   => DB::raw('cantidad + ' . (int)$r->cantidad),
                            'updated_at' => now(),
                        ]);
                }

                DB::table('reservas_stock')
                    ->where('pedido_id', $pedidoId)
                    ->where('estado', 'confirmada')
                    ->update([
                        'estado'      => 'devuelta',
                        'devuelta_en' => now(),
                        'updated_at'  => now(),
                    ]);
            }

            // 6) Devolver contenedores confirmados
            app(ContenedorReservaService::class)
                ->devolverPorPedido($pedidoId, $data['motivo'] ?? null);

            // 7) Marcar pedido como devuelto
            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado'       => 'devuelto',
                'nota_interna' => $data['motivo'] ?? ($pedido->nota_interna ?? null),
                'updated_at'   => now(),
            ]);

            return $refund;
        });

        return response()->json([
            'ok'      => true,
            'message' => 'Pedido devuelto correctamente.',
            'refund'  => $refundResult,
        ], 200);
    }

    public function solicitarDevolucion(Request $request, int $pedidoId)
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $user    = auth('sanctum')->user();
        $cliente = $user->cliente;

        if (!$cliente) {
            return response()->json(['error' => 'No hay cliente asociado a este usuario.'], 404);
        }

        DB::transaction(function () use ($pedidoId, $data, $cliente) {

            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->where('cliente_id', $cliente->id)
                ->lockForUpdate()
                ->first();

            if (!$pedido) {
                abort(404, 'Pedido no encontrado.');
            }

            $estadosSolicitables = ['pagado', 'preparando', 'enviado', 'entregado'];
            if (!in_array($pedido->estado, $estadosSolicitables, true)) {
                abort(409, 'No se puede solicitar devolución para un pedido en estado: ' . $pedido->estado . '.');
            }

            if ($pedido->estado === 'devolucion_solicitada') {
                abort(409, 'Ya existe una solicitud de devolución para este pedido.');
            }

            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado_antes_devolucion'  => $pedido->estado,
                'estado'                   => 'devolucion_solicitada',
                'motivo_devolucion'        => $data['motivo'],
                'devolucion_solicitada_en' => now(),
                'updated_at'               => now(),
            ]);
        });

        return response()->json([
            'ok'      => true,
            'message' => 'Solicitud de devolución enviada. Será revisada por nuestro equipo.',
        ], 200);
    }

    public function verComprobante(int $pedidoId)
    {
        $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();

        if (!$pedido) {
            return response()->json(['error' => 'Pedido no encontrado.'], 404);
        }

        if (empty($pedido->comprobante_path)) {
            return response()->json(['error' => 'Este pedido no tiene comprobante adjunto.'], 404);
        }

        if (!Storage::disk('local')->exists($pedido->comprobante_path)) {
            return response()->json(['error' => 'Archivo no encontrado en el servidor.'], 404);
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($pedido->comprobante_path)) {
            return response()->json(['error' => 'Archivo no encontrado'], 404);
        }

        $contenido = $disk->get($pedido->comprobante_path);

        $mime = \Illuminate\Support\Facades\File::mimeType(
            storage_path('app/' . $pedido->comprobante_path)
        );

        return response($contenido, 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="comprobante-pedido-' . $pedidoId . '"');
    }

    public function rechazarPago(Request $request, int $pedidoId)
    {
        $data = $request->validate([
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($pedidoId, $data) {

            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->lockForUpdate()
                ->first();

            if (!$pedido) abort(404, 'Pedido no encontrado.');

            if ($pedido->estado !== 'pendiente_pago') {
                abort(409, 'Solo se puede rechazar un pago en estado pendiente_pago.');
            }

            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado'       => 'cancelado',
                'nota_interna' => $data['motivo'] ? ('Pago rechazado: ' . $data['motivo']) : 'Pago rechazado por administrador.',
                'updated_at'   => now(),
            ]);

            DB::table('reservas_stock')
                ->where('pedido_id', $pedidoId)
                ->where('estado', 'activa')
                ->update(['estado' => 'liberada', 'updated_at' => now()]);
        });

        return response()->json(['ok' => true, 'message' => 'Pago rechazado. El pedido fue cancelado.'], 200);
    }

    public function rechazarDevolucion(Request $request, int $pedidoId)
    {
        $data = $request->validate([
            'motivo_rechazo' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($pedidoId, $data) {

            $pedido = DB::table('pedidos')
                ->where('id', $pedidoId)
                ->lockForUpdate()
                ->first();

            if (!$pedido) abort(404, 'Pedido no encontrado.');

            if ($pedido->estado !== 'devolucion_solicitada') {
                abort(409, 'El pedido no tiene una solicitud de devolución pendiente.');
            }

            $estadoAnterior = $pedido->estado_antes_devolucion ?? 'pagado';

            DB::table('pedidos')->where('id', $pedidoId)->update([
                'estado'                  => $estadoAnterior,
                'estado_antes_devolucion' => null,
                'nota_interna'            => 'Devolución rechazada: ' . $data['motivo_rechazo'],
                'updated_at'              => now(),
            ]);
        });

        return response()->json([
            'ok'      => true,
            'message' => 'Solicitud de devolución rechazada.',
        ], 200);
    }
}
