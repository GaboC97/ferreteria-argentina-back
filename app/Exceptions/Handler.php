<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        // Dejar que Laravel maneje correctamente las excepciones conocidas
        // (validación, auth, not found, etc.) en todos los entornos
        if ($e instanceof ValidationException ||
            $e instanceof AuthenticationException ||
            $e instanceof ModelNotFoundException ||
            $e instanceof HttpException) {
            return parent::render($request, $e);
        }

        // Solo ocultar excepciones inesperadas en producción
        if (app()->environment('production')) {
            return response()->json([
                'message' => 'Ocurrió un error inesperado. Intente nuevamente.'
            ], 500);
        }

        return parent::render($request, $e);
    }
}
