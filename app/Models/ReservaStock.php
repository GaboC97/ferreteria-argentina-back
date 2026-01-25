<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservaStock extends Model
{
    protected $table = 'reservas_stock';

    protected $fillable = [
        'pedido_id',
        'producto_id',
        'sucursal_id',
        'cantidad',
        'estado',
        'vence_en',
    ];

    protected $casts = [
        'vence_en' => 'datetime',
    ];

    /* ================== RELACIONES ================== */

    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
}
