<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSucursal extends Model
{
    protected $table = 'stock_sucursal';
    public $timestamps = true;

    protected $fillable = [
        'producto_id',
        'sucursal_id',
        'cantidad',
    ];

    protected $casts = [
        'cantidad' => 'int',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }
}
