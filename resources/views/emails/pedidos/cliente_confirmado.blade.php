@component('mail::message')
# ✅ ¡Gracias por tu compra, {{ $pedido->nombre_contacto ?? 'Cliente' }}!

Tu pedido **#{{ $pedido->id }}** fue **confirmado** y el pago se acreditó correctamente.

{{-- ✅ AGREGADO: Fecha del pedido --}}
**Fecha:** {{ $pedido->created_at->format('d/m/Y') }}

---

## 📦 Resumen del pedido
@component('mail::table')
| Producto | Cant. | Precio | Subtotal |
|:--|--:|--:|--:|
@foreach(($pedido->items ?? []) as $it)
| {{ $it->nombre_producto ?? ('Producto #' . $it->producto_id) }} | {{ (int) $it->cantidad }} | ${{ number_format((float) $it->precio_unitario, 2, ',', '.') }} | ${{ number_format((float) $it->subtotal, 2, ',', '.') }} |
@endforeach
@endcomponent

**Total:** ${{ number_format((float) $pedido->total_final, 2, ',', '.') }} {{ $pedido->moneda ?? 'ARS' }}

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

@if($pedido->envio->empresa)
**Empresa:** {{ $pedido->envio->empresa }}
@endif
@if($pedido->envio->tracking_codigo)
**Tracking:** {{ $pedido->envio->tracking_codigo }}
@endif
@else
**Sucursal:** {{ optional($pedido->sucursal)->nombre ?? 'Sucursal seleccionada' }}
@endif

---

## 🧾 Datos del comprador
- **Nombre:** {{ $pedido->nombre_contacto ?? '-' }}
- **DNI:** {{ optional($pedido->cliente)->dni ?? '-' }}
- **Email:** {{ $pedido->email_contacto ?? '-' }}
- **Teléfono:** {{ $pedido->telefono_contacto ?? '-' }}


@if($pedido->nota_cliente)
---

## 📝 Nota
{{ $pedido->nota_cliente }}
@endif

---

Gracias,  
**Ferretería Argentina RW**
@endcomponent