<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    protected $table = 'sucursales';
    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'ciudad',
        'direccion',
        'telefono',
        'activo',
    ];

    protected $casts = [
        'activo' => 'bool',
    ];

    public function stock(): HasMany
    {
        return $this->hasMany(StockSucursal::class, 'sucursal_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'sucursal_id');
    }
}
