<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Cliente;
use App\Services\PaljetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use App\Mail\VerifyEmailOtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => ['required', 'string', 'max:120'],
            'apellido'  => ['required', 'string', 'max:120'],
            'dni'       => ['nullable', 'string', 'max:20'],
            'telefono'  => ['required', 'string', 'max:40'],
            'email'     => ['required', 'string', 'email', 'max:160', 'unique:users,email'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
            // Campos opcionales
            'cuil_cuit'     => ['nullable', 'string', 'max:20', 'unique:clientes,cuit'],
            'direccion'     => ['nullable', 'string', 'max:160'],
            'codigo_postal' => ['nullable', 'string', 'max:20'],
            'localidad'     => ['nullable', 'string', 'max:80'],
            'provincia'     => ['nullable', 'string', 'max:80'],
        ], [
            'email.unique'     => 'Ya existe una cuenta con ese email. Por favor iniciá sesión.',
            'cuil_cuit.unique' => 'Ya existe una cuenta con ese CUIT/CUIL. Por favor iniciá sesión.',
        ]);

        // ── Lookup en Paljet ANTES de la transacción (evita locks durante HTTP) ──
        $paljetClienteId = null;
        $paljetData      = null;
        $cuil_cuit       = $data['cuil_cuit'] ?? null;

        if ($cuil_cuit) {
            try {
                $paljet = app(PaljetService::class);

                // Caso 1 o 2: buscar cliente por CUIT
                $paljetClienteId = $paljet->buscarClientePorCuitODni($cuil_cuit);

                if ($paljetClienteId) {
                    // Caso 1: encontrado → traer datos para sobreescribir los del front
                    $paljetData = $paljet->getClientePaljet($paljetClienteId);
                } else {
                    // Caso 2: no encontrado → crear en Paljet y guardar el ID devuelto
                    $paljetClienteId = $paljet->crearCliente([
                        'nombre'        => $data['nombre'],
                        'apellido'      => $data['apellido'] ?? null,
                        'email'         => $data['email'],
                        'telefono'      => $data['telefono'] ?? null,
                        'cuit'          => $cuil_cuit,
                        'condicion_iva' => 'Consumidor Final',
                        'direccion'     => $data['direccion'] ?? null,
                        'codigo_postal' => $data['codigo_postal'] ?? null,
                        'localidad'     => $data['localidad'] ?? null,
                        'provincia'     => $data['provincia'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                // Fallo silencioso: continuar registro sin Paljet
                Log::warning('Paljet - Fallo silencioso durante registro, se omite lookup', [
                    'error' => $e->getMessage(),
                    'email' => $data['email'],
                ]);
                $paljetClienteId = null;
                $paljetData      = null;
            }
        }
        // Caso 3: cuil_cuit no enviado → no se hace lookup; paljetClienteId queda null

        // Extraer DNI del CUIL/CUIT: "20-40384587-9" → "40384587"
        $dni = null;
        if ($cuil_cuit) {
            $parts = explode('-', $cuil_cuit);
            if (count($parts) === 3) {
                $dni = $parts[1]; // Formato con guiones
            } else {
                $clean = preg_replace('/\D/', '', $cuil_cuit);
                if (strlen($clean) === 11) {
                    $dni = substr($clean, 2, 8); // Sin guiones: tomar dígitos 3-10
                }
            }
        }

        $result = DB::transaction(function () use ($data, $cuil_cuit, $dni, $paljetClienteId, $paljetData) {

            $user = new User([
                'name'     => $data['nombre'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->rol              = 'cliente';
            $user->email_verified_at = null;
            $user->save();

            // Campos base del cliente con datos del front
            $clienteData = [
                'user_id'                  => $user->id,
                'nombre'                   => $data['nombre'],
                'apellido'                 => $data['apellido'],
                'email'                    => $data['email'],
                'telefono'                 => $data['telefono'],
                'dni'                      => $data['dni'] ?? $dni,
                'cuit'                     => $cuil_cuit,
                'direccion_calle'          => $data['direccion'] ?? null,
                'direccion_localidad'      => $data['localidad'] ?? null,
                'direccion_provincia'      => $data['provincia'] ?? null,
                'direccion_codigo_postal'  => $data['codigo_postal'] ?? null,
                'activo'                   => true,
                'paljet_cliente_id'        => $paljetClienteId,
            ];

            // Si Paljet encontró el cliente (Caso 1), sus datos tienen prioridad
            if ($paljetData) {
                // Nombre: Paljet guarda como 'rz' (razón social)
                if (!empty($paljetData['rz'])) {
                    $clienteData['nombre'] = $paljetData['rz'];
                }

                // Teléfono: primer número por defecto
                $telefonos = $paljetData['telefonos'] ?? [];
                $telDefault = null;
                foreach ($telefonos as $tel) {
                    if ($tel['por_defecto'] ?? false) {
                        $telDefault = $tel;
                        break;
                    }
                }
                if (!$telDefault && !empty($telefonos)) {
                    $telDefault = $telefonos[0];
                }
                if ($telDefault && !empty($telDefault['numero'])) {
                    $clienteData['telefono'] = $telDefault['numero'];
                }

                // Domicilio: primer domicilio por defecto
                $domicilios = $paljetData['domicilios'] ?? [];
                $domDefault = null;
                foreach ($domicilios as $dom) {
                    if (($dom['por_defecto'] ?? '') === 'S' || ($dom['por_defecto'] ?? false) === true) {
                        $domDefault = $dom;
                        break;
                    }
                }
                if (!$domDefault && !empty($domicilios)) {
                    $domDefault = $domicilios[0];
                }
                if ($domDefault) {
                    $calle = trim(($domDefault['calle'] ?? '') . ' ' . ($domDefault['calle_nro'] ?? ''));
                    if ($calle) {
                        $clienteData['direccion_calle'] = $calle;
                    }
                    if (!empty($domDefault['cp_nuevo'])) {
                        $clienteData['direccion_codigo_postal'] = $domDefault['cp_nuevo'];
                    }
                }
            }

            $cliente = Cliente::create($clienteData);

            // OTP 6 dígitos
            $code = (string) random_int(100000, 999999);
            $user->email_otp            = $code;
            $user->email_otp_expires_at = now()->addMinutes(10);
            $user->save();

            Mail::mailer('verificaciones')->to($user->email)->send(new VerifyEmailOtpMail($code, $user->name));

            return compact('user', 'cliente');
        });

        return response()->json([
            'message'            => 'Te enviamos un código a tu email para verificar tu cuenta.',
            'needs_verification' => true,
            'email'              => $result['user']->email,
        ], 201);
    }

    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $exists = User::where('email', $request->email)->exists();

        return response()->json(['exists' => $exists]);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();

        if (!$user->email_verified_at) {
            Auth::logout();
            return response()->json([
                'message' => 'Debés verificar tu email antes de iniciar sesión.',
                'needs_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // traer cliente si existe
        $cliente = Cliente::where('user_id', $user->id)->first();

        return response()->json([
            'user' => $user,
            'cliente' => $cliente,
        ])->withCookie($this->authCookie($token));
    }

    public function user(Request $request): JsonResponse
    {
        $user    = $request->user();
        $cliente = $user ? Cliente::where('user_id', $user->id)->first() : null;

        return response()->json([
            'id'                => $user->id,
            'email'             => $user->email,
            'nombre'            => $cliente?->nombre,
            'apellido'          => $cliente?->apellido,
            'dni'               => $cliente?->dni,
            'cuil_cuit'         => $cliente?->cuit,
            'telefono'          => $cliente?->telefono,
            'direccion'         => $cliente?->direccion_calle,
            'codigo_postal'     => $cliente?->direccion_codigo_postal,
            'localidad'         => $cliente?->direccion_localidad,
            'provincia'         => $cliente?->direccion_provincia,
            'condicion_iva'     => $cliente?->condicion_iva,
            'nombre_empresa'    => $cliente?->nombre_empresa,
            'paljet_cliente_id' => $cliente?->paljet_cliente_id,
            'cliente'           => $cliente ? [
                'id'                      => $cliente->id,
                'nombre'                  => $cliente->nombre,
                'apellido'                => $cliente->apellido,
                'dni'                     => $cliente->dni,
                'cuit'                    => $cliente->cuit,
                'telefono'                => $cliente->telefono,
                'condicion_iva'           => $cliente->condicion_iva,
                'nombre_empresa'          => $cliente->nombre_empresa,
                'direccion_calle'         => $cliente->direccion_calle,
                'direccion_numero'        => $cliente->direccion_numero ?? null,
                'direccion_piso'          => $cliente->direccion_piso ?? null,
                'direccion_depto'         => $cliente->direccion_depto ?? null,
                'direccion_localidad'     => $cliente->direccion_localidad,
                'direccion_provincia'     => $cliente->direccion_provincia,
                'direccion_codigo_postal' => $cliente->direccion_codigo_postal,
            ] : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada exitosamente'])
            ->withCookie(cookie()->forget('myFerreteriaAuthToken'));
    }


    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'min:6', 'max:6'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || $user->email_verified_at) {
            return response()->json(['message' => 'Código inválido o email ya verificado.'], 422);
        }

        if (!$user->email_otp || !$user->email_otp_expires_at || now()->greaterThan($user->email_otp_expires_at)) {
            return response()->json(['message' => 'Código vencido. Pedí uno nuevo.'], 422);
        }

        if (!hash_equals($user->email_otp, $data['code'])) {
            return response()->json(['message' => 'Código incorrecto'], 422);
        }

        $user->email_verified_at = now();
        $user->email_otp = null;
        $user->email_otp_expires_at = null;
        $user->save();

        $token = $user->createToken('auth_token')->plainTextToken;
        $cliente = Cliente::where('user_id', $user->id)->first();

        return response()->json([
            'user' => $user,
            'cliente' => $cliente,
        ])->withCookie($this->authCookie($token));
    }

public function updatePerfil(Request $request): JsonResponse
{
    $user = $request->user();

    $cliente = Cliente::where('user_id', $user->id)->first();

    if (!$cliente) {
        return response()->json([
            'message' => 'No hay cliente asociado a este usuario'
        ], 404);
    }

    $data = $request->validate([
        'nombre' => ['sometimes','string','max:120'],
        'apellido' => ['sometimes','string','max:120'],
        'telefono' => ['sometimes','string','max:40'],
        'dni' => ['sometimes','string','max:20'],

        'cuit' => ['nullable','string','max:20'],
        'condicion_iva' => ['nullable','string','max:80'],
        'nombre_empresa' => ['nullable','string','max:160'],

        // Dirección (en tabla clientes)
        'direccion_calle' => ['nullable','string','max:160'],
        'direccion_numero' => ['nullable','string','max:20'],
        'direccion_piso' => ['nullable','string','max:20'],
        'direccion_depto' => ['nullable','string','max:20'],
        'direccion_localidad' => ['nullable','string','max:80'],
        'direccion_provincia' => ['nullable','string','max:80'],
        'direccion_codigo_postal' => ['nullable','string','max:20'],
    ]);

    // 🔥 Update cliente
    $cliente->update($data);

    // 🔥 Sync nombre en tabla users si cambia
    if (isset($data['nombre'])) {
        $user->name = $data['nombre'];
        $user->save();
    }

    return response()->json([
        'message' => 'Perfil actualizado correctamente',
        'cliente' => $cliente->fresh(),
    ]);
}


    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Por seguridad no revelamos si el email existe o no
        if (!$user) {
            return response()->json(['message' => 'Si el email existe, enviamos un código para restablecer tu contraseña.']);
        }

        // Cooldown: si el OTP anterior aún no venció (quedan >8 min), no reenviar
        if ($user->reset_password_otp_expires_at && Carbon::parse($user->reset_password_otp_expires_at)->subMinutes(2)->isFuture()) {
            return response()->json(['message' => 'Si el email existe, enviamos un código para restablecer tu contraseña.']);
        }

        $otp = (string) random_int(100000, 999999);

        $user->reset_password_otp            = $otp;
        $user->reset_password_otp_expires_at = now()->addMinutes(10);
        $user->save();

        Mail::mailer('verificaciones')->raw(
            "Tu código para restablecer la contraseña es: {$otp}. Vence en 10 minutos.\n\nSi no solicitaste esto, ignorá este mensaje.",
            function ($message) use ($user) {
                $message->from('verificacionesopt@ferrear.com.ar', 'Ferrear')
                        ->to($user->email)
                        ->subject('Restablecer contraseña');
            }
        );

        return response()->json(['message' => 'Si el email existe, enviamos un código para restablecer tu contraseña.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'                 => ['required', 'email'],
            'code'                  => ['required', 'string', 'min:6', 'max:6'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !$user->reset_password_otp) {
            return response()->json(['message' => 'Código inválido o expirado.'], 422);
        }

        if (now()->greaterThan($user->reset_password_otp_expires_at)) {
            return response()->json(['message' => 'El código expiró. Pedí uno nuevo.'], 422);
        }

        if (!hash_equals($user->reset_password_otp, $data['code'])) {
            return response()->json(['message' => 'Código incorrecto.'], 422);
        }

        $user->password                      = \Illuminate\Support\Facades\Hash::make($data['password']);
        $user->reset_password_otp            = null;
        $user->reset_password_otp_expires_at = null;
        $user->tokens()->delete(); // Invalida todas las sesiones activas
        $user->save();

        return response()->json(['message' => 'Contraseña restablecida correctamente. Ya podés iniciar sesión.']);
    }

    public function resendEmailOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Por seguridad, no revelamos si existe o no
        if (!$user) {
            return response()->json([
                'message' => 'Si el email existe, enviamos un nuevo código.',
            ]);
        }

        // Cooldown: si el OTP anterior aún no venció (quedan >8 min), no reenviar
        if ($user->email_otp_expires_at && Carbon::parse($user->email_otp_expires_at)->subMinutes(2)->isFuture()) {
            return response()->json(['message' => 'Si el email existe, enviamos un nuevo código.']);
        }

        $otp = (string) random_int(100000, 999999);

        $user->email_otp = $otp;
        $user->email_otp_expires_at = now()->addMinutes(10);
        $user->save();

        // Enviar mail (acá podés usar Mailable, pero te dejo simple)
        Mail::mailer('verificaciones')->raw("Tu código de verificación es: {$otp}. Vence en 10 minutos.", function ($message) use ($user) {
            $message->from('verificacionesopt@ferrear.com.ar', 'Ferrear')
                    ->to($user->email)
                    ->subject('Código de verificación');
        });

        return response()->json([
            'message' => 'Código reenviado. Revisá tu correo.',
        ]);
    }

    private function authCookie(string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        $secure   = (bool) env('COOKIE_SECURE', true);
        $sameSite = env('COOKIE_SAME_SITE', 'none');

        return cookie(
            'myFerreteriaAuthToken',
            $token,
            config('sanctum.expiration', 1440),
            '/',
            null,
            $secure,    // SameSite=none requiere Secure=true
            true,       // HttpOnly
            false,
            $sameSite
        );
    }
}
