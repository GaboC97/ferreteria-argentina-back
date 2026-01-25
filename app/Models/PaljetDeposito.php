<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaljetDeposito extends Model
{
    protected $table = 'paljet_depositos';

    protected $fillable = ['paljet_id','nombre','activo','raw_json'];

    protected $casts = [
        'activo' => 'boolean',
        'raw_json' => 'array',
    ];
}
