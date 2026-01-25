<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $fillable = [
        'cliente_id',
        'sucursal_id',
        'tipo_entrega',
        'nombre_contacto',
        'email_contacto',
        'telefono_contacto',
        'estado',
        'total_productos',
        'costo_envio',
        'total_final',
        'moneda',
        'nota_cliente',
        'nota_interna',
    ];

    protected $casts = [
        'total_productos' => 'decimal:2',
        'costo_envio'     => 'decimal:2',
        'total_final'     => 'decimal:2',
    ];

    /* ================== RELACIONES ================== */

    public function pagos()
    {
        return $this->hasMany(Pago::class);
    }

    public function reservasStock()
    {
        return $this->hasMany(ReservaStock::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function items()
    {
        return $this->hasMany(PedidoItem::class, 'pedido_id');
    }

    public function envio()
    {
        return $this->hasOne(Envio::class, 'pedido_id');
    }

    public function contenedorReservas(): HasMany
    {
        return $this->hasMany(ContenedorReserva::class, 'pedido_id');
    }
}
