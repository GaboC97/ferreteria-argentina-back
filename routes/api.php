<?php

use Illuminate\Support\Facades\Route;

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
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DireccionController;
use App\Http\Controllers\ContactoController;
use App\Http\Controllers\PostulacionController;

// =====================
// AUTH (PÚBLICAS) - Rate limited para prevenir brute force
// =====================
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmailOtp']);
    Route::post('/resend-verification', [AuthController::class, 'resendEmailOtp']);
    Route::post('/login', [AuthController::class, 'login']);
});

// =====================
// AUTH (PROTEGIDAS)
// =====================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/clientes/perfil', [ClienteController::class, 'updatePerfil']);
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
Route::post('/postulaciones', [PostulacionController::class, 'store']);

Route::get('/catalogo', [PaljetCatalogoController::class, 'index']);
Route::get('/catalogo/{paljetId}', [PaljetCatalogoController::class, 'show']);

// =====================
// PEDIDOS Y PAGOS
// =====================
Route::post('/pedidos', [PedidoController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/pedidos', [PedidoController::class, 'index']);
    Route::get('/pedidos/{id}', [PedidoController::class, 'show']);
    Route::post('/pedidos/{id}/solicitar-devolucion', [PedidoController::class, 'solicitarDevolucion']);
});

Route::post('/pedidos/{id}/comprobante', [PedidoController::class, 'subirComprobante']);

Route::post('/pagos/mercadopago/preferencia', [MercadoPagoController::class, 'crearPreferencia']);
Route::get('/pagos/mercadopago/estado', [MercadoPagoController::class, 'estado']);
Route::any('/webhooks/mercadopago', [WebhookController::class, 'mercadoPago']);

// =====================
// DIRECCIONES (PROTEGIDAS)
// =====================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/direcciones', [DireccionController::class, 'index']);
    Route::post('/direcciones', [DireccionController::class, 'store']);
    Route::put('/direcciones/{id}', [DireccionController::class, 'update']);
    Route::delete('/direcciones/{id}', [DireccionController::class, 'destroy']);
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
    Route::post('/pedidos/{id}/devolver', [PedidoController::class, 'devolver']);
    Route::post('/pedidos/{id}/rechazar-devolucion', [PedidoController::class, 'rechazarDevolucion']);
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
