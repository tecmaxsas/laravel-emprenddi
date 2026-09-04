<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\ThirdParty;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Correo con el estado de cuenta del cliente adjunto en PDF. */
class CustomerStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ThirdParty $customer,
        public ?Company $company,
        public string $subject,
        public string $body,
        public float $due,
        protected string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
            from: $this->company?->email
                ? new Address($this->company->email, $this->company->name)
                : null,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'sales.customer-statement-mail',
            with: [
                'customer' => $this->customer,
                'company' => $this->company,
                'body' => $this->body,
                'due' => $this->due,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $nombre = 'estado-cuenta-'.Str::slug($this->customer->name).'.pdf';

        return [
            Attachment::fromData(fn () => $this->pdfContent, $nombre)
                ->withMime('application/pdf'),
        ];
    }
}
