<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        // Verificar si el usuario tiene rol de admin
        if ($user->rol !== 'admin') {
            return response()->json([
                'error' => 'No autorizado',
                'message' => 'No tienes permisos de administrador para acceder a este recurso'
            ], 403);
        }

        return $next($request);
    }
}
