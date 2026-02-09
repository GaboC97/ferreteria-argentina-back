<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostulacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $datos;
    private string $cvPath;

    public function __construct(array $datos, string $cvPath)
    {
        $this->datos = $datos;
        $this->cvPath = $cvPath;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nueva postulación: ' . $this->datos['puesto'] . ' - ' . $this->datos['nombre'],
            replyTo: [$this->datos['email']],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.postulacion',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorage($this->cvPath),
        ];
    }
}
