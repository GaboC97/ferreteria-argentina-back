@component('mail::message')
@php
    $fmt = fn($n) => '$' . number_format((float)$n, 2, ',', '.');
@endphp

@if($esAdmin)
# ✅ Pago confirmado

El pago del pedido **{{ $pedido->numero_comprobante ?? ('#' . $pedido->id) }}** fue aprobado.
@else
# ✅ ¡Tu pago fue confirmado, {{ explode(' ', $pedido->nombre_contacto ?? 'Cliente')[0] }}!

Tu pedido fue pagado correctamente. Ya lo estamos preparando.
@endif

**N° de comprobante:** `{{ $pedido->numero_comprobante ?? ('#' . $pedido->id) }}`
**Fecha:** {{ $pedido->created_at->format('d/m/Y H:i') }}

@if(!$esAdmin && $pedido->tipo_entrega === 'retiro_sucursal')
@component('mail::panel')
**🎫 Código de retiro: {{ $pedido->numero_comprobante ?? ('#' . $pedido->id) }}**

Presentá este código en la sucursal para retirar tu pedido. Podés mostrarlo desde tu celular o imprimirlo.
@endcomponent

@endif

---

## 📦 Productos
@component('mail::table')
| Producto | Cant. | Precio unit. | Subtotal |
|:--|--:|--:|--:|
@foreach(($pedido->items ?? []) as $it)
| {{ $it->nombre_producto ?? ('Artículo #' . ($it->paljet_art_id ?? $it->producto_id)) }} | {{ (int) $it->cantidad }} | {{ $fmt($it->precio_unitario) }} | {{ $fmt($it->subtotal) }} |
@endforeach
@endcomponent

**Total: {{ $fmt($pedido->total_final) }} {{ $pedido->moneda ?? 'ARS' }}**

---

## 🚚 Entrega
**Tipo:** {{ $pedido->tipo_entrega === 'envio' ? 'Envío a domicilio' : 'Retiro en sucursal' }}

@if($pedido->tipo_entrega === 'envio' && $pedido->envio)
**Dirección:** {{ $pedido->envio->calle }} {{ $pedido->envio->numero }}
@if($pedido->envio->piso || $pedido->envio->depto)
, Piso {{ $pedido->envio->piso ?? '-' }} - Depto {{ $pedido->envio->depto ?? '-' }}
@endif

**Ciudad / Provincia:** {{ $pedido->envio->ciudad }}, {{ $pedido->envio->provincia }}
@if($pedido->envio->codigo_postal)
**CP:** {{ $pedido->envio->codigo_postal }}
@endif
@if($pedido->envio->referencias)
**Referencias:** {{ $pedido->envio->referencias }}
@endif

@if(!$esAdmin)
@component('mail::panel')
Tu pedido será entregado en un plazo de **48 a 72 horas hábiles** desde la confirmación del pago.
@endcomponent
@endif
@else
**Sucursal:** {{ optional($pedido->sucursal)->nombre ?? 'Sucursal seleccionada' }}
@endif

---

## 🧾 Datos del comprador
- **Nombre:** {{ $pedido->nombre_contacto ?? '-' }}
- **DNI:** {{ $pedido->dni_contacto ?? '-' }}
@if($pedido->cuit_contacto)
- **CUIT:** {{ $pedido->cuit_contacto }}
@endif
- **Condición IVA:** {{ $pedido->condicion_iva_contacto ?? '-' }}
- **Email:** {{ $pedido->email_contacto ?? '-' }}
- **Teléfono:** {{ $pedido->telefono_contacto ?? '-' }}

@if($pedido->nota_cliente)
---

## 📝 Nota del cliente
{{ $pedido->nota_cliente }}
@endif

---

Gracias,
**Ferretería Argentina RW**
@endcomponent
