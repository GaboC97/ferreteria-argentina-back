<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetArticulo extends Model
{
    protected $table = 'paljet_articulos';

    protected $fillable = [
        'paljet_id',
        'codigo',
        'ean',
        'descripcion',
        'desc_cliente',
        'familia_path',
        'familia_id',
        'escala_id',
        'escala_nombre',
        'escala_abrev',
        'publica_web',
        'admin_existencia',
        'impuestos_json',
        'raw_json',
    ];

    protected $casts = [
        'publica_web' => 'boolean',
        'admin_existencia' => 'boolean',
        'impuestos_json' => 'array',
        'raw_json' => 'array',
    ];
}
