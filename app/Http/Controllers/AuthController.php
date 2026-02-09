<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Mail\VerifyEmailOtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'apellido' => ['required', 'string', 'max:120'],
            'dni' => ['required', 'string', 'max:20', 'unique:clientes,dni'],
            'telefono' => ['required', 'string', 'max:40'],
            'email' => ['required', 'string', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = DB::transaction(function () use ($data) {

            $user = new User([
                'name' => $data['nombre'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->rol = 'cliente';
            $user->email_verified_at = null;
            $user->save();

            $cliente = Cliente::create([
                'user_id' => $user->id,
                'nombre' => $data['nombre'],
                'apellido' => $data['apellido'],
                'email' => $data['email'],
                'telefono' => $data['telefono'],
                'dni' => $data['dni'],
                'activo' => true,
            ]);

            // ✅ OTP 6 dígitos
            $code = (string) random_int(100000, 999999);
            $user->email_otp = $code;
            $user->email_otp_expires_at = now()->addMinutes(10);
            $user->save();

            // enviar mail fuera? lo hacemos dentro porque es simple
            Mail::to($user->email)->send(new VerifyEmailOtpMail($code, $user->name));

            return compact('user', 'cliente');
        });

        // ✅ No devolvemos token aún
        return response()->json([
            'message' => 'Te enviamos un código a tu email para verificar tu cuenta.',
            'needs_verification' => true,
            'email' => $result['user']->email,
        ], 201);
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
            'token' => $token,
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $cliente = $user ? Cliente::where('user_id', $user->id)->first() : null;

        return response()->json([
            'user' => $user,
            'cliente' => $cliente,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada exitosamente']);
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
            'token' => $token,
        ]);
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
        Mail::raw("Tu código de verificación es: {$otp}. Vence en 10 minutos.", function ($message) use ($user) {
            $message->to($user->email)->subject('Código de verificación');
        });

        return response()->json([
            'message' => 'Código reenviado. Revisá tu correo.',
        ]);
    }
}
