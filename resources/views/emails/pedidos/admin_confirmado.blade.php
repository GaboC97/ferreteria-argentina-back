@component('mail::message')
# 🧾 Nueva compra confirmada

Se confirmó el pago de un pedido.

@php
  // Este mail SOLO se envía cuando el pago fue aprobado
  $estadoLabel = 'Pagado';

  $fmt = fn($n) => '$' . number_format((float)$n, 2, ',', '.');
@endphp

---

## 📌 Pedido
- **Pedido Nº:** {{ $pedido->id }}
{{-- ✅ AQUÍ AGREGAMOS LA FECHA --}}
- **Fecha:** {{ $pedido->created_at->format('d/m/Y H:i') }}
- **Estado:** {{ $estadoLabel }}
- **Total:** {{ $fmt($pedido->total_final) }} {{ $pedido->moneda ?? 'ARS' }}
- **Entrega:** {{ $pedido->tipo_entrega === 'envio' ? 'Envío a domicilio' : 'Retiro en sucursal' }}

---

## 👤 Comprador
- **Nombre:** {{ $pedido->nombre_contacto ?? '-' }}
- **Email:** {{ $pedido->email_contacto ?? '-' }}
- **Teléfono:** {{ $pedido->telefono_contacto ?? '-' }}

@if($pedido->cliente)
- **Cliente ID:** {{ $pedido->cliente->id }}
@endif

---

## 📦 Items
@component('mail::table')
| Producto | Cant. | Precio | Subtotal |
|:--|--:|--:|--:|
@foreach($pedido->items as $it)
| {{ $it->nombre_producto }} | {{ $it->cantidad }} | {{ $fmt($it->precio_unitario) }} | {{ $fmt($it->subtotal) }} |
@endforeach
@endcomponent

---

## 🚚 Envío / Retiro
@if($pedido->tipo_entrega === 'envio' && $pedido->envio)
- **Dirección:** {{ $pedido->envio->calle }} {{ $pedido->envio->numero }}
@if($pedido->envio->piso || $pedido->envio->depto)
, Piso {{ $pedido->envio->piso ?? '-' }} - Depto {{ $pedido->envio->depto ?? '-' }}
@endif
- **Ciudad / Provincia:** {{ $pedido->envio->ciudad }}, {{ $pedido->envio->provincia }}
@else
- **Sucursal:** {{ optional($pedido->sucursal)->nombre ?? 'Sucursal seleccionada' }}
@endif

@if($pedido->nota_cliente)
---

## 📝 Nota del cliente
{{ $pedido->nota_cliente }}
@endif

@endcomponent