<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContenedorReservaConfirmadaCliente extends Mailable
{
  use Queueable, SerializesModels;

  public $pedido;
  public $reserva;
  public $productoNombre;

  public function __construct($pedido, $reserva, $productoNombre = null)
  {
    $this->pedido = $pedido;
    $this->reserva = $reserva;
    $this->productoNombre = $productoNombre;
  }

  public function build()
  {
    return $this->subject('✅ Reserva de contenedor confirmada')
      ->markdown('emails.contenedor.cliente_confirmada');
  }
}

