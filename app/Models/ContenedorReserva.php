<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContenedorReserva extends Model
{
    protected $table = 'contenedor_reservas';

    protected $fillable = [
        'pedido_item_id',
        'pedido_id',
        'producto_id',
        'fecha_entrega',
        'fecha_retiro',
        'localidad',
        'domicilio',
        'codigo_postal',
        'telefono',
        'cantidad',
        'cuenta_corriente',
        'comprobante_path',
        'estado',
        'observaciones',
        'fecha_retiro_real',
    ];

    protected $casts = [
        'fecha_entrega' => 'date',
        'fecha_retiro' => 'date',
        'fecha_retiro_real' => 'date',
        'cuenta_corriente' => 'boolean',
        'cantidad' => 'integer',
    ];

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
