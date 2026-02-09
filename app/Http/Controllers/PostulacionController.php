<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\PostulacionMail;

class PostulacionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'  => ['required', 'string', 'max:255'],
            'telefono'=> ['required', 'string', 'max:50'],
            'email'   => ['required', 'email', 'max:255'],
            'puesto'  => ['required', 'string', 'max:255'],
            'mensaje' => ['nullable', 'string', 'max:2000'],
            'cv'      => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ]);

        $cv = $request->file('cv');
        $cvName = time() . '_' . Str::random(20) . '.' . $cv->extension();
        $cvPath = $cv->storeAs('postulaciones', $cvName);

        Mail::to('rrhh@ferrear.com.ar')->send(new PostulacionMail($data, $cvPath));

        return response()->json([
            'message' => 'Postulación recibida correctamente.',
        ], 200);
    }
}
