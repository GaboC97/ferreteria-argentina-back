<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoFoto extends Model
{
    protected $table = 'productos_fotos';

    protected $fillable = [
        'codigo_producto',
        'tiene_foto',
    ];

    protected $casts = [
        'tiene_foto' => 'boolean',
    ];
}
