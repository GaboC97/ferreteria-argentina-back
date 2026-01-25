<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoImagen extends Model
{
    protected $table = 'producto_imagenes';
    public $timestamps = true;

    protected $fillable = [
        'producto_id',
        'url',
        'principal',
        'orden',
    ];

    protected $casts = [
        'principal' => 'bool',
        'orden' => 'int',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
