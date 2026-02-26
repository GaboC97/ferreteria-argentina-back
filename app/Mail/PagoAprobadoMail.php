<?php

namespace App\Mail;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PagoAprobadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Pedido $pedido,
        public readonly bool $esAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->esAdmin
            ? "Pago confirmado - Pedido #{$this->pedido->id} - {$this->pedido->nombre_contacto}"
            : "¡Pago confirmado! Pedido #{$this->pedido->id}";

        return new Envelope(
            from: new Address('pagos@ferrear.com.ar', 'Ferrear - Pagos'),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pagos.aprobado',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
