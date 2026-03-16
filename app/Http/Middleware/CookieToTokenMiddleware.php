<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Lee el token de autenticación desde la cookie HttpOnly "myFerreteriaAuthToken"
 * y lo inyecta como header "Authorization: Bearer <token>" para que Sanctum
 * pueda autenticar la request normalmente.
 *
 * Sólo actúa si no hay ya un header Authorization en la request.
 */
class CookieToTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->cookie('myFerreteriaAuthToken');

        if ($token && !$request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
