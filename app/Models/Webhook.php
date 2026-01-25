<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $table = 'webhooks';
    public $timestamps = true;

    protected $fillable = [
        'proveedor',
        'evento',
        'external_id',
        'payload_json',
        'procesado',
        'procesado_en',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'procesado' => 'bool',
        'procesado_en' => 'datetime',
    ];
}
