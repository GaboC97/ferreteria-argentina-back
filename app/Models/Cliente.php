<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'clientes';

    protected $fillable = [
        'user_id',
        'nombre',
        'apellido',
        'email',
        'password',
        'telefono',
        'dni',
        'cuit',
        'condicion_iva',
        'nombre_empresa',
        'direccion_calle',
        'direccion_numero',
        'direccion_piso',
        'direccion_depto',
        'direccion_localidad',
        'direccion_provincia',
        'direccion_codigo_postal',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'activo' => 'boolean',
    ];

    /* ================== RELACIONES ================== */

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'cliente_id');
    }

    // Nota: La relación con 'direcciones' puede ser eliminada si
    // decidimos mantener solo la dirección principal en la tabla de clientes.
    // Si quieres múltiples direcciones por cliente, la mantenemos.
    // Por ahora, la comentaré para simplificar.
    /*
    public function direcciones(): HasMany
    {
        return $this->hasMany(Direccion::class, 'cliente_id');
    }
    */

    public function user()
{
    return $this->belongsTo(\App\Models\User::class);
}

public function direcciones()
{
    return $this->hasMany(Direccion::class, 'cliente_id');
}


}
