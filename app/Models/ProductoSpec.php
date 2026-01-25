<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoSpec extends Model
{
    protected $table = 'producto_specs';

    protected $fillable = [
        'producto_id', 'clave', 'valor', 'orden',
    ];
}
