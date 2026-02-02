<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

use App\Http\Controllers\PedidoController;
use App\Http\Controllers\MercadoPagoController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\PaljetCatalogoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ContactoController;

// =====================
// AUTH (PÚBLICAS)
// =====================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verifyEmailOtp']);
Route::post('/resend-verification', [AuthController::class, 'resendEmailOtp']);
Route::post('/login', [AuthController::class, 'login']);

// =====================
// AUTH (PROTEGIDAS)
// =====================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/clientes/perfil', [AuthController::class, 'updatePerfil']);
});

// =====================
// TIENDA PÚBLICA
// =====================
Route::get('/productos', [ProductoController::class, 'index']);
Route::get('/productos/marcas', [ProductoController::class, 'marcas']);
Route::get('/productos/{slug}', [ProductoController::class, 'show']);
Route::get('/categorias', [CategoriaController::class, 'index']);
Route::get('/marcas', [MarcaController::class, 'index']);
Route::get('/sucursales', [SucursalController::class, 'index']);
Route::get('/sucursales/{id}', [SucursalController::class, 'show']);
Route::post('/contacto', [ContactoController::class, 'store']);

Route::get('/catalogo', [PaljetCatalogoController::class, 'index']);
Route::get('/catalogo/{paljetId}', [PaljetCatalogoController::class, 'show']);

// =====================
// PEDIDOS Y PAGOS
// =====================
Route::post('/pedidos', [PedidoController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/pedidos', [PedidoController::class, 'index']);
    Route::get('/pedidos/{id}', [PedidoController::class, 'show']);
});

Route::post('/pedidos/{id}/comprobante', [PedidoController::class, 'subirComprobante']);

Route::post('/pagos/mercadopago/preferencia', [MercadoPagoController::class, 'crearPreferencia']);
Route::get('/pagos/mercadopago/estado', [MercadoPagoController::class, 'estado']);
Route::any('/webhooks/mercadopago', [WebhookController::class, 'mercadoPago']);

Route::get('/mp/preference/{id}', function ($id) {
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

// =====================
// RUTAS ADMIN (requieren autenticación + rol admin)
// =====================
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Dashboard y estadísticas
    Route::get('/dashboard', [AdminController::class, 'dashboard']);

    // Gestión de productos
    Route::get('/productos', [AdminController::class, 'productos']);
    Route::get('/productos/stats', [AdminController::class, 'productosStats']);
    Route::post('/productos', [AdminController::class, 'crearProducto']);
    Route::put('/productos/{id}', [AdminController::class, 'actualizarProducto']);
    Route::delete('/productos/{id}', [AdminController::class, 'eliminarProducto']);

    // Gestión de clientes
    Route::get('/clientes', [AdminController::class, 'clientes']);
    Route::put('/clientes/{id}', [AdminController::class, 'actualizarCliente']);

    // Configuración
    Route::get('/configuracion', [AdminController::class, 'configuracion']);
    Route::put('/configuracion', [AdminController::class, 'actualizarConfiguracion']);

    // Gestión de sucursales
    Route::post('/sucursales', [AdminController::class, 'crearSucursal']);
    Route::put('/sucursales/{id}', [AdminController::class, 'actualizarSucursal']);
    Route::delete('/sucursales/{id}', [AdminController::class, 'eliminarSucursal']);
});

// Gestión de pedidos (admin) - fuera del prefix para mantener /api/pedidos/{id}
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::put('/pedidos/{id}', [PedidoController::class, 'update']);
});
