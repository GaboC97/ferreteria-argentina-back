<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactoMail;

class ContactoController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'  => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email', 'max:160'],
            'telefono'=> ['nullable', 'string', 'max:40'],
            'asunto'  => ['required', 'string', 'max:200'],
            'mensaje' => ['required', 'string', 'max:2000'],
        ]);

        $adminEmail = config('mail.ferreteria.notif_email');

        if (!$adminEmail) {
            Log::warning('ContactoController: mail.ferreteria.notif_email no configurado.');
            return response()->json([
                'message' => 'Tu mensaje fue enviado correctamente. Te responderemos a la brevedad.',
            ], 200);
        }

        Mail::to($adminEmail)->send(new ContactoMail($data));

        return response()->json([
            'message' => 'Tu mensaje fue enviado correctamente. Te responderemos a la brevedad.',
        ], 200);
    }
}
