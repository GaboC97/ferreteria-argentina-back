@component('mail::message')
# 🧾 Reserva de contenedor confirmada (Pago acreditado)

@php
  $fmt = fn($n) => '$' . number_format((float)$n, 2, ',', '.');
@endphp

---

## 📌 Reserva
- **Reserva Nº:** {{ $reserva->id }}
- **Pedido Nº:** {{ $reserva->pedido_id }}
- **Estado:** Pagado
- **Producto:** {{ $productoNombre ?? ('Producto #' . $reserva->producto_id) }}

@if(!empty($reserva->comprobante))
- **Comprobante:** {{ $reserva->comprobante }}
@endif

---

## 👤 Cliente
- **Nombre:** {{ $pedido->nombre_contacto ?? '-' }}
- **Email:** {{ $pedido->email_contacto ?? '-' }}
- **Teléfono:** {{ $reserva->telefono ?? ($pedido->telefono_contacto ?? '-') }}

---

## 🚚 Entrega
{{-- ✅ AQUÍ EL CAMBIO --}}
- **Fecha:** {{ !empty($reserva->fecha_entrega) ? \Carbon\Carbon::parse($reserva->fecha_entrega)->format('d/m/Y') : '-' }}
- **Localidad:** {{ $reserva->localidad ?? '-' }}
- **Domicilio:** {{ $reserva->domicilio ?? '-' }}
- **CP:** {{ $reserva->codigo_postal ?? '-' }}

---

## ♻️ Retiro
{{-- ✅ AQUÍ EL CAMBIO --}}
- **Fecha:** {{ !empty($reserva->fecha_retiro) ? \Carbon\Carbon::parse($reserva->fecha_retiro)->format('d/m/Y') : '-' }}

@if(!empty($reserva->observaciones))
---
## 📝 Observaciones
{{ $reserva->observaciones }}
@endif

@endcomponent