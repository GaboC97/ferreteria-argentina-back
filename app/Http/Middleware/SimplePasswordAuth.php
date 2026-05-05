<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SimplePasswordAuth
{
    /**
     * Valida que el request incluya la contraseña o el token derivado de ella.
     *
     * Se acepta vía:
     *   - Header:  X-Productos-Password: <password|token>
     *   - Header:  Authorization: Bearer <token>
     *   - Body:    { "password": "<password|token>" }
     *
     * El token es sha256(password), generado en el login y guardado en localStorage.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('app.productos_password');

        if (!$expected) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $provided = $request->bearerToken()
            ?? $request->header('X-Productos-Password')
            ?? $request->input('password');

        $provided = (string) $provided;

        $validPassword = hash_equals($expected, $provided);
        $validToken    = hash_equals(hash('sha256', $expected), $provided);

        if (!$validPassword && !$validToken) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
