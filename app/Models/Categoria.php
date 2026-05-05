<?php

namespace App\Models;

use App\Models\CatalogoWeb;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Categoria extends Model
{
    protected $table = 'categorias';
    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'slug',
        'categoria_padre_id',
        'activo',
    ];

    protected $casts = [
        'activo' => 'bool',
    ];

    /* ================== RELACIONES ================== */

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_padre_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_padre_id');
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(CatalogoWeb::class, 'categoria_web_id');
    }
}
