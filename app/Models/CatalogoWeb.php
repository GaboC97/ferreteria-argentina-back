<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoWeb extends Model
{
    protected $table      = 'catalogo_web';
    protected $primaryKey = 'paljet_art_id';
    public    $incrementing = false;
    public    $timestamps   = false;

    protected $fillable = [
        'paljet_art_id',
        'codigo',
        'ean',
        'descripcion',
        'desc_cliente',
        'desc_mod_med',
        'marca_id',
        'marca_nombre',
        'familia_id',
        'familia_nombre',
        'categoria_id',
        'categoria_nombre',
        'precio',
        'precio_neto',
        'iva_alicuota',
        'admin_existencia',
        'stock',
        'ultimas_unidades',
        'imagen_url',
        'tiene_imagen',
        'listas_json',
        'raw_json',
        'synced_at',
        'first_seen_at',
        'ventas_count',
    ];

    protected $casts = [
        'paljet_art_id'   => 'integer',
        'marca_id'        => 'integer',
        'familia_id'      => 'integer',
        'categoria_id'    => 'integer',
        'precio'          => 'float',
        'precio_neto'     => 'float',
        'iva_alicuota'    => 'float',
        'stock'           => 'float',
        'admin_existencia' => 'boolean',
        'ultimas_unidades' => 'boolean',
        'tiene_imagen'    => 'boolean',
        'listas_json'     => 'array',
        'raw_json'        => 'array',
        'synced_at'       => 'datetime',
        'first_seen_at'   => 'datetime',
    ];
}
