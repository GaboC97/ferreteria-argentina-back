<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\PedidoController;
use App\Http\Controllers\GetnetController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\PaljetCatalogoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\MetricasController;
use App\Http\Controllers\PaljetArticulosOcultosController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DireccionController;
use App\Http\Controllers\ContactoController;
use App\Http\Controllers\PostulacionController;
use App\Http\Controllers\ProductoPlayaUnionController;

// =====================
// PRODUCTOS PLAYA UNIÓN (protegido por contraseña simple)
// =====================
Route::post('/playa-union/login', [ProductoPlayaUnionController::class, 'login']);

Route::middleware('productos.pass')->prefix('playa-union')->group(function () {
    Route::get('/productos', [ProductoPlayaUnionController::class, 'index']);
    Route::post('/productos/fotos', [ProductoPlayaUnionController::class, 'storeFotos']);
});

// =====================
// AUTH (PÚBLICAS) - Rate limited para prevenir brute force
// =====================
Route::post('/check-email', [AuthController::class, 'checkEmail']);

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmailOtp']);
    Route::post('/resend-verification', [AuthController::class, 'resendEmailOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
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
Route::get('/catalogo/categorias', [PaljetCatalogoController::class, 'categorias']);
Route::get('/catalogo/marcas', [PaljetCatalogoController::class, 'marcas']);
// Admin: artículos sin stock en Playa Unión (debe ir antes del wildcard {paljetId})
Route::middleware(['auth:sanctum', 'admin'])->get('/catalogo/sin-stock', [PaljetCatalogoController::class, 'sinStock']);
Route::get('/catalogo/{codigo}/imagen', [PaljetCatalogoController::class, 'imagen']);
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

Route::post('/pagos/getnet/sesion', [GetnetController::class, 'crearSesion']);
Route::get('/pagos/getnet/estado', [GetnetController::class, 'estado']);
Route::any('/webhooks/getnet', [WebhookController::class, 'getnet']);

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

    // Métricas
    Route::prefix('metricas')->group(function () {
        Route::get('/kpis', [MetricasController::class, 'kpis']);
        Route::get('/ingresos-mensuales', [MetricasController::class, 'ingresosMensuales']);
        Route::get('/pedidos-por-estado', [MetricasController::class, 'pedidosPorEstado']);
        Route::get('/comparativa-anual', [MetricasController::class, 'comparativaAnual']);
    });

    // Gestión de productos
    Route::get('/productos', [AdminController::class, 'productos']);
    Route::get('/productos/stats', [AdminController::class, 'productosStats']);
    Route::post('/productos', [AdminController::class, 'crearProducto']);
    Route::put('/productos/{id}', [AdminController::class, 'actualizarProducto']);
    Route::delete('/productos/{id}', [AdminController::class, 'eliminarProducto']);
    Route::patch('/productos/{id}/oferta', [AdminController::class, 'toggleOferta']);
    Route::post('/pedidos/{id}/devolver', [PedidoController::class, 'devolver']);
    Route::post('/pedidos/{id}/rechazar-devolucion', [PedidoController::class, 'rechazarDevolucion']);
    // Gestión de clientes
    Route::get('/clientes', [AdminController::class, 'clientes']);
    Route::get('/clientes/stats', [AdminController::class, 'clientesStats']);
    Route::put('/clientes/{id}', [AdminController::class, 'actualizarCliente']);

    // Configuración
    Route::get('/configuracion', [AdminController::class, 'configuracion']);
    Route::put('/configuracion', [AdminController::class, 'actualizarConfiguracion']);

    // Artículos ocultos del catálogo Paljet
    Route::get('/catalogo/ocultos', [PaljetArticulosOcultosController::class, 'index']);
    Route::post('/catalogo/ocultos', [PaljetArticulosOcultosController::class, 'store']);
    Route::delete('/catalogo/ocultos/{paljetArtId}', [PaljetArticulosOcultosController::class, 'destroy']);

    // Gestión de sucursales
    Route::post('/sucursales', [AdminController::class, 'crearSucursal']);
    Route::put('/sucursales/{id}', [AdminController::class, 'actualizarSucursal']);
    Route::delete('/sucursales/{id}', [AdminController::class, 'eliminarSucursal']);

    // Export sin stock
    Route::get('/catalogo/sin-stock/export', function () {
        $productos = \Illuminate\Support\Facades\DB::table('catalogo_web')
            ->where('stock', '<=', 0)
            ->orderBy('marca_nombre')
            ->orderBy('descripcion')
            ->get(['codigo', 'descripcion', 'marca_nombre', 'familia_nombre', 'precio', 'stock']);

        $fecha = now()->format('Y-m-d');

        $csv = "\xEF\xBB\xBF"; // BOM UTF-8 para que Excel lo abra bien
        $csv .= "Código;Descripción;Marca;Familia;Precio;Stock\n";

        foreach ($productos as $p) {
            $csv .= implode(';', [
                '"' . str_replace('"', '""', $p->codigo        ?? '') . '"',
                '"' . str_replace('"', '""', $p->descripcion   ?? '') . '"',
                '"' . str_replace('"', '""', $p->marca_nombre  ?? '') . '"',
                '"' . str_replace('"', '""', $p->familia_nombre ?? '') . '"',
                number_format((float) $p->precio, 2, ',', '.'),
                (int) $p->stock,
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"sin_stock_{$fecha}.csv\"",
        ]);
    });
});

// Gestión de pedidos (admin) - fuera del prefix para mantener /api/pedidos/{id}
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::put('/pedidos/{id}', [PedidoController::class, 'update']);
    Route::post('/pedidos/{id}/confirmar-pago', [PedidoController::class, 'confirmarPago']);
    Route::post('/pedidos/{id}/rechazar-pago', [PedidoController::class, 'rechazarPago']);
    Route::get('/pedidos/{id}/comprobante', [PedidoController::class, 'verComprobante']);
});

