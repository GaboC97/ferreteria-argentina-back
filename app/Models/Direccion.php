<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Direccion extends Model
{
    protected $table = 'direcciones';
    public $timestamps = true;

    protected $fillable = [
        'cliente_id',
        'alias',
        'nombre_recibe',
        'telefono_recibe',
        'calle',
        'numero',
        'piso',
        'depto',
        'ciudad',
        'provincia',
        'codigo_postal',
        'referencias',
        'es_principal',
    ];

    protected $casts = [
        'es_principal' => 'bool',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
