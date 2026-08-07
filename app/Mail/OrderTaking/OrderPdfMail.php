<?php

namespace App\Mail\OrderTaking;

use App\Models\Company;
use App\Models\OrderTaking\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con el PDF del pedido adjunto. El asunto y cuerpo los define el
 * usuario en el modal antes de enviar.
 */
class OrderPdfMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?Company $company,
        public string $subject,
        public string $body,
        protected string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
            from: $this->company?->email
                ? new \Illuminate\Mail\Mailables\Address($this->company->email, $this->company->name)
                : null,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'order-taking.order-mail',
            with: [
                'order' => $this->order,
                'company' => $this->company,
                'body' => $this->body,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, 'Pedido-'.$this->order->fullNumber().'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
