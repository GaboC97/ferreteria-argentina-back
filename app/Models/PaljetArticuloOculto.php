<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetArticuloOculto extends Model
{
    protected $table = 'paljet_articulos_ocultos';

    protected $fillable = ['paljet_art_id', 'motivo'];
}
