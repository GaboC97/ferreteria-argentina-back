<?php

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\GetnetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GetnetController extends Controller
{
    public function __construct(private GetnetService $getnet) {}

    /**
     * Crea un Payment Intent en Getnet y devuelve el payment_intent_id al frontend.
     *
     * El frontend debe:
     *  1. Recibir el payment_intent_id de este endpoint
     *  2. Cargar: <script src="https://www.globalgetnet.com/digital-checkout/loader.js">
     *  3. Llamar: loader.init({ paymentIntentId, checkoutType: "iframe" })
     *  4. Getnet renderiza el formulario de pago dentro del iFrame automáticamente
     *  5. El resultado llega por webhook a /api/webhooks/getnet
     */
    public function crearSesion(Request $request)
    {
        $data = $request->validate([
            'pedido_id'    => ['required', 'integer'],
            'access_token' => ['nullable', 'string', 'uuid'],
        ]);

        $pedidoId = (int) $data['pedido_id'];
        $pedido   = Pedido::findAuthorizedOrFail($pedidoId, $request);

        if ($pedido->estado !== 'pendiente_pago') {
            return response()->json([
                'message' => 'Este pedido no puede pagarse en este momento.',
            ], 409);
        }

        $medioGetnet = DB::table('medios_pago')->where('codigo', 'getnet')->first();
        if (!$medioGetnet) {
            return response()->json(['message' => 'El medio de pago no está disponible.'], 500);
        }

        if (!config('getnet.client_id') || !config('getnet.client_secret')) {
            return response()->json(['message' => 'El servicio de pagos no está configurado.'], 500);
        }

        // Reutilizar sesión existente si todavía está activa
        $pagoExistente = DB::table('pagos')
            ->where('pedido_id', $pedidoId)
            ->whereNotNull('getnet_payment_id')
            ->whereIn('estado', ['iniciado', 'pendiente'])
            ->orderByDesc('id')
            ->first();

        if ($pagoExistente && $pagoExistente->getnet_payment_id) {
            return response()->json([
                'ok'                => true,
                'pedido_id'         => $pedidoId,
                'pago_id'           => $pagoExistente->id,
                'payment_intent_id' => $pagoExistente->getnet_payment_id,
                'loader_url'        => $this->getnet->getLoaderScriptUrl(),
            ]);
        }

        // Traer items del pedido
        $items = DB::table('pedido_items as pi')
            ->leftJoin('productos as p', 'p.id', '=', 'pi.producto_id')
            ->where('pi.pedido_id', $pedidoId)
            ->select(
                'pi.cantidad',
                'pi.precio_unitario',
                DB::raw('COALESCE(p.nombre, pi.nombre_producto) as nombre')
            )
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'El pedido no tiene productos.'], 409);
        }

        $monto   = (float) $pedido->total_final;
        $moneda  = config('getnet.currency', 'ARS');
        $orderId = "pedido-{$pedidoId}-" . Str::random(8);

        // Crear registro de pago local
        $pagoId = DB::table('pagos')->insertGetId([
            'pedido_id'              => $pedidoId,
            'medio_pago_id'          => $medioGetnet->id,
            'estado'                 => 'iniciado',
            'monto'                  => $monto,
            'moneda'                 => $moneda,
            'getnet_order_id'        => $orderId,
            'getnet_idempotency_key' => (string) Str::uuid(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        // Armar productos para el payload
        $productos = $items->map(fn($it) => [
            'product_type' => 'physical_goods',
            'title'        => $it->nombre,
            'description'  => $it->nombre,
            'value'        => (int) round((float) $it->precio_unitario * 100),
            'quantity'     => (int) $it->cantidad,
        ])->values()->all();

        // Datos del cliente — first_name y last_name son requeridos por Getnet
        $firstName = trim($pedido->nombre_contacto ?? '');
        $lastName  = trim($pedido->apellido_contacto ?? '');

        // Si solo tenemos un campo con el nombre completo, separar en palabras
        if ($firstName !== '' && $lastName === '') {
            $parts     = explode(' ', $firstName, 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? '-';
        }

        $payload = [
            'order_id' => $orderId,
            'payment'  => [
                'currency' => $moneda,
                'amount'   => (int) round($monto * 100),
            ],
            'product'  => $productos,
            'customer' => array_filter([
                'customer_id' => (string) ($pedido->user_id ?? $pedidoId),
                'email'       => $pedido->email_contacto ?? null,
                'first_name'  => $firstName ?: '-',
                'last_name'   => $lastName  ?: '-',
                'name'        => trim("$firstName $lastName") ?: null,
            ]),
        ];

        try {
            $respGetnet = $this->getnet->crearPaymentIntent($payload);
        } catch (\Throwable $e) {
            Log::error('Getnet crearSesion error', [
                'pedido_id' => $pedidoId,
                'pago_id'   => $pagoId,
                'error'     => $e->getMessage(),
            ]);

            DB::table('pagos')->where('id', $pagoId)->update([
                'estado'          => 'rechazado',
                'getnet_status'   => 'session_error',
                'getnet_raw_json' => json_encode(['error' => $e->getMessage()]),
                'updated_at'      => now(),
            ]);

            return response()->json([
                'message' => 'No se pudo iniciar el proceso de pago. Intentá nuevamente.',
            ], 502);
        }

        $paymentIntentId = data_get($respGetnet, 'payment_intent_id');

        DB::table('pagos')->where('id', $pagoId)->update([
            'getnet_payment_id' => $paymentIntentId,
            'getnet_status'     => 'initiated',
            'getnet_raw_json'   => json_encode(Pago::sanitizeGetnetRaw((array) $respGetnet)),
            'updated_at'        => now(),
        ]);

        return response()->json([
            'ok'                => true,
            'pedido_id'         => $pedidoId,
            'pago_id'           => $pagoId,
            'payment_intent_id' => $paymentIntentId,
            'loader_url'        => $this->getnet->getLoaderScriptUrl(),
        ]);
    }

    /**
     * Consulta el estado actual del pago para un pedido.
     */
    public function estado(Request $request)
    {
        $data = $request->validate([
            'pedido_id'    => ['required', 'integer'],
            'access_token' => ['nullable', 'string', 'uuid'],
        ]);

        $pedidoId = (int) $data['pedido_id'];
        $pedido   = Pedido::findAuthorizedOrFail($pedidoId, $request);

        if ($pedido->estado === 'pagado') {
            return response()->json([
                'ok'          => true,
                'pedido_id'   => $pedidoId,
                'status'      => 'Authorized',
                'pago_estado' => 'aprobado',
                'note'        => 'already_paid_local',
            ]);
        }

        $pago = DB::table('pagos')
            ->where('pedido_id', $pedidoId)
            ->orderByDesc('id')
            ->first();

        if (!$pago || !$pago->getnet_payment_id) {
            return response()->json([
                'ok'          => true,
                'pedido_id'   => $pedidoId,
                'status'      => 'Pending',
                'pago_estado' => $pago->estado ?? 'iniciado',
            ]);
        }

        try {
            $respGetnet = $this->getnet->obtenerPaymentIntent($pago->getnet_payment_id);
            $status     = data_get($respGetnet, 'payment.result.status')
                ?? data_get($respGetnet, 'status')
                ?? $pago->getnet_status;

            return response()->json([
                'ok'          => true,
                'pedido_id'   => $pedidoId,
                'status'      => $status,
                'pago_estado' => $this->mapStatus($status),
            ]);
        } catch (\Throwable $e) {
            Log::error('Getnet estado error', [
                'pedido_id'  => $pedidoId,
                'payment_id' => $pago->getnet_payment_id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'ok'          => true,
                'pedido_id'   => $pedidoId,
                'status'      => $pago->getnet_status ?? 'Pending',
                'pago_estado' => $pago->estado ?? 'pendiente',
                'note'        => 'fallback_local',
            ]);
        }
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'Authorized'                   => 'aprobado',
            'Pending', 'initiated'         => 'pendiente',
            'Denied', 'Rejected', 'Error'  => 'rechazado',
            'Cancelled', 'Canceled'        => 'cancelado',
            default                        => 'pendiente',
        };
    }
}
