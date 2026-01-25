<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class PedidoItem extends Model
{
    protected $table = 'pedido_items';
    public $timestamps = true;

    protected $fillable = [
        'pedido_id',
        'producto_id',
        'nombre_producto',
        'precio_unitario',
        'cantidad',
        'subtotal',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'cantidad' => 'int',
        'subtotal' => 'decimal:2',
        'extras' => 'array',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function contenedorReserva(): HasOne
    {
        return $this->hasOne(ContenedorReserva::class, 'pedido_item_id');
    }
}
