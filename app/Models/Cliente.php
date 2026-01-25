<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';
    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'telefono',
        'password_hash',
        'activo',
        'email_verificado_en',
    ];

    protected $casts = [
        'activo' => 'bool',
        'email_verificado_en' => 'datetime',
    ];

    /* ================== RELACIONES ================== */

    public function direcciones(): HasMany
    {
        return $this->hasMany(Direccion::class, 'cliente_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'cliente_id');
    }
}
