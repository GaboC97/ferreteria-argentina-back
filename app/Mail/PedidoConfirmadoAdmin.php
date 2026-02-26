<?php

namespace App\Mail;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PedidoConfirmadoAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Pedido $pedido)
    {
        $this->pedido->loadMissing(['items', 'envio', 'pagos', 'sucursal', 'cliente']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('pedidos@ferrear.com.ar', 'Ferrear - Pedidos'),
            subject: "Nuevo pedido pagado #{$this->pedido->id}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pedidos.admin_confirmado',
            with: ['pedido' => $this->pedido],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
