<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'slug' => $this->slug,
            'precio' => $this->precio,
            'marca' => $this->marca,
            'unidad' => $this->unidad,
            'descripcion' => $this->descripcion,
            'destacado' => (bool) $this->destacado,
            'image' => $this->image,

            // 👇 clave para el front
            'categoria_id' => $this->categoria_id,
            'categoria' => $this->whenLoaded('categoria', fn() => [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
                'slug' => $this->categoria->slug,
            ]),

            'imagenes' => $this->whenLoaded('imagenes'),

                            'specs' => $this->specs->map(fn($s) => [
                    'clave' => $s->clave,
                    'valor' => $s->valor,
                ])->values(),
        ];
    }
}
