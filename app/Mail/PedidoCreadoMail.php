<?php

namespace App\Mail;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PedidoCreadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Pedido $pedido,
        public readonly bool $esAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->esAdmin
            ? "Nuevo pedido #{$this->pedido->id} - {$this->pedido->nombre_contacto}"
            : "Tu pedido #{$this->pedido->id} fue recibido";

        return new Envelope(
            from: new Address('pedidos@ferrear.com.ar', 'Ferrear - Pedidos'),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pedidos.creado',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
