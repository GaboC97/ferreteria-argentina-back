<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetPrecio extends Model
{
    protected $table = 'paljet_precios';

    protected $fillable = [
        'lista_id',
        'articulo_id',
        'pr_vta',
        'pr_final',
        'moneda',
        'raw_json',
    ];

    protected $casts = [
        'raw_json' => 'array',
    ];
}
