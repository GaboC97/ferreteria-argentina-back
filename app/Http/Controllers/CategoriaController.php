<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Categoria;

class CategoriaController extends Controller
{
    public function index()
{
    return Categoria::where('activo', true)
        ->orderBy('nombre')
        ->get(['id', 'nombre', 'slug']);
}

}
