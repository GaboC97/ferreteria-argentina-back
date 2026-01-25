<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

use App\Http\Controllers\PedidoController;
use App\Http\Controllers\MercadoPagoController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\PaljetCatalogoController;

Route::post('/pedidos', [PedidoController::class, 'store']);
Route::get('/pedidos/{id}', [PedidoController::class, 'show']);

// ✅ Checkout Pro: crear preferencia y redirigir al init_point
Route::post('/pagos/mercadopago/preferencia', [MercadoPagoController::class, 'crearPreferencia']);

// ✅ Consulta de estado (para CheckoutResultView)
Route::get('/pagos/mercadopago/estado', [MercadoPagoController::class, 'estado']);

// ✅ Webhook Mercado Pago
Route::any('/webhooks/mercadopago', [WebhookController::class, 'mercadoPago']);

// (Opcional) Debug preference
Route::get('/mp/preference/{id}', function ($id) {
    // ⚠️ OJO: en tu código tenés mezclado config('mercadopago.access_token') y config('services.mercadopago.access_token')
    // Elegí UNO. Recomiendo: config('services.mercadopago.access_token')
    $r = Http::withToken(config('services.mercadopago.access_token'))
        ->acceptJson()
        ->get("https://api.mercadopago.com/checkout/preferences/{$id}");

    return response()->json([
        'status' => $r->status(),
        'collector_id' => $r->json()['collector_id'] ?? null,
        'sandbox_init_point' => $r->json()['sandbox_init_point'] ?? null,
        'init_point' => $r->json()['init_point'] ?? null,
        'raw' => $r->json(),
    ]);
});

// Productos / categorías
Route::get('/productos', [ProductoController::class, 'index']);
Route::get('/productos/{slug}', [ProductoController::class, 'show']);
Route::get('/categorias', [CategoriaController::class, 'index']);




Route::get('/catalogo', [PaljetCatalogoController::class, 'index']);
Route::get('/catalogo/{paljetId}', [PaljetCatalogoController::class, 'show']);
