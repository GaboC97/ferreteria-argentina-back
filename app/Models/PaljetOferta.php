<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetOferta extends Model
{
    protected $table      = 'paljet_ofertas';
    protected $primaryKey = 'paljet_art_id';
    public $incrementing  = false;
    protected $keyType    = 'integer';

    protected $fillable = ['paljet_art_id', 'precio_oferta'];

    protected $casts = [
        'precio_oferta' => 'float',
    ];
}
