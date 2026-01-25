<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'pedido_id',
        'medio_pago_id',
        'estado',
        'monto',
        'moneda',
        'mp_preference_id',
        'mp_payment_id',
        'mp_merchant_order_id',
        'mp_status',
        'mp_status_detail',
        'mp_raw_json',
        'aprobado_en',
    ];

    protected $casts = [
        'monto'        => 'decimal:2',
        'mp_raw_json'  => 'array',
        'aprobado_en'  => 'datetime',
    ];

    /* ================== RELACIONES ================== */

    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }
}
