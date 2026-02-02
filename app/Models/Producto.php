<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Producto extends Model
{
    protected $table = 'productos';
    public $timestamps = true;

    protected $fillable = [
        'categoria_id',
        'marca_id',
        'nombre',
        'slug',
        'codigo',
        'descripcion',
        'precio',
        'unidad',
        'activo',
        'destacado',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'activo' => 'bool',
        'destacado' => 'bool',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function stockSucursales(): HasMany
    {
        return $this->hasMany(StockSucursal::class, 'producto_id');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(ProductoImagen::class, 'producto_id');
    }

    public function specs()
    {
        return $this->hasMany(\App\Models\ProductoSpec::class, 'producto_id')
            ->orderBy('orden');
    }

    public function marca()
    {
        return $this->belongsTo(Marca::class);
    }
}
