<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetListaPrecio extends Model
{
    protected $table = 'paljet_listas_precios';

    protected $fillable = [
        'paljet_id',
        'nombre',
        'activa',
        'raw_json',
    ];

    protected $casts = [
        'activa' => 'boolean',
        'raw_json' => 'array',
    ];
}
