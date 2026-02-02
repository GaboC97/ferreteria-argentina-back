<?php

namespace App\Mail;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PedidoConfirmadoCliente extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Pedido $pedido)
    {
        // Cargamos lo que vamos a mostrar
        $this->pedido->loadMissing(['items', 'envio', 'pagos', 'sucursal']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "✅ Pedido #{$this->pedido->id} confirmado"
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pedidos.cliente_confirmado',
            with: ['pedido' => $this->pedido],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
