<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedioPago extends Model
{
    protected $table = 'medios_pago';
    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'codigo',
        'activo',
    ];

    protected $casts = [
        'activo' => 'bool',
    ];

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'medio_pago_id');
    }
}
