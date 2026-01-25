<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoContacto extends Model
{
    protected $table = 'pedido_contactos';
    public $timestamps = true;

    protected $fillable = [
        'pedido_id',
        'nombre',
        'email',
        'telefono',
        'direccion',      // opcional para envíos
        'localidad',
        'provincia',
        'codigo_postal',
        'notas',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
