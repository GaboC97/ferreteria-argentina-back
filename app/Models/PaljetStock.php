<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetStock extends Model
{
    protected $table = 'paljet_stock';

    protected $fillable = [
        'deposito_id',
        'articulo_id',
        'existencia',
        'disponible',
        'comprometido',
        'a_recibir',
        'stk_min',
        'raw_json',
    ];

    protected $casts = [
        'raw_json' => 'array',
    ];
}
