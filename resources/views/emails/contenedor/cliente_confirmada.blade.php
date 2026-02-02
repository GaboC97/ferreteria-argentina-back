@component('mail::message')
# ✅ ¡Reserva confirmada!

Hola {{ $pedido->nombre_contacto ?? 'Cliente' }},  
tu **reserva de contenedor** quedó confirmada y el pago se acreditó correctamente.

@php
  $fmt = fn($n) => '$' . number_format((float)$n, 2, ',', '.');
@endphp

---

## 📌 Datos de la reserva
- **Reserva Nº:** {{ $reserva->id }}
- **Pedido Nº:** {{ $reserva->pedido_id }}
- **Producto:** {{ $productoNombre ?? ('Producto #' . $reserva->producto_id) }}

---

## 🚚 Entrega
{{-- ✅ FECHA FORMATEADA --}}
- **Fecha:** {{ !empty($reserva->fecha_entrega) ? \Carbon\Carbon::parse($reserva->fecha_entrega)->format('d/m/Y') : '-' }}
- **Localidad:** {{ $reserva->localidad ?? '-' }}
- **Domicilio:** {{ $reserva->domicilio ?? '-' }}
- **CP:** {{ $reserva->codigo_postal ?? '-' }}
- **Teléfono:** {{ $reserva->telefono ?? ($pedido->telefono_contacto ?? '-') }}

---

## ♻️ Retiro
{{-- ✅ FECHA FORMATEADA --}}
- **Fecha:** {{ !empty($reserva->fecha_retiro) ? \Carbon\Carbon::parse($reserva->fecha_retiro)->format('d/m/Y') : '-' }}

@if(!empty($reserva->observaciones))
---
## 📝 Observaciones
{{ $reserva->observaciones }}
@endif

---

Gracias,  
**Ferretería Argentina RW**
@endcomponent