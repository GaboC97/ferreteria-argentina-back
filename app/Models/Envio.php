<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Envio extends Model
{
    protected $table = 'envios';
    public $timestamps = true;

    protected $fillable = [
        'pedido_id',
        'calle',
        'numero',
        'piso',
        'depto',
        'ciudad',
        'provincia',
        'codigo_postal',
        'referencias',
        'estado',
        'empresa',
        'tracking_codigo',
        'despachado_en',
        'entregado_en',
    ];

    protected $casts = [
        'despachado_en' => 'datetime',
        'entregado_en' => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
