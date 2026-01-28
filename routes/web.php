<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    // Esta es la ruta raíz, útil para que Laravel sepa que la app está viva.
    return ['app' => 'Mi Tienda API', 'version' => '1.0.0'];
});

// --- RUTAS DE AUTENTICACIÓN PARA LA API ---

// Rutas para invitados (no requieren autenticación)
Route::post('login', [AuthController::class, 'login']);
Route::post('registro', [AuthController::class, 'register']);

// Ruta que requiere autenticación
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // Aquí podrías agregar otras rutas que requieran que el cliente esté logueado
    // Por ejemplo: GET /api/mi-cuenta
});
