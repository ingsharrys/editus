<?php

namespace App\Mail;

use App\Models\Contrato;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Copia del contrato firmado, con el PDF adjunto. */
class ContratoFirmado extends Mailable
{
    public function __construct(public Contrato $k, private string $pdf, private string $archivo)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Contrato No. :n firmado – :c', ['n' => $this->k->codigo, 'c' => $this->k->campaign->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contrato', with: [
            'k' => $this->k, 'c' => $this->k->campaign,
            'nombre' => $this->k->dato('representante', $this->k->dato('medio_nombre')),
        ]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn() => $this->pdf, $this->archivo)->withMime('application/pdf')];
    }
}
