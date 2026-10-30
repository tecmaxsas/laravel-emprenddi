<?php

namespace App\Mail;

use App\Models\Hotel\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La confirmación de la reserva, por correo.
 *
 * El texto NO se arma aquí: sale de `HotelConfirmacion`, el mismo que arma el
 * de WhatsApp. Si cada canal escribiera el suyo, un día el correo lleva la
 * hora de entrada y el WhatsApp no, y el huésped llega a mediodía a una
 * habitación que se entrega a las tres.
 */
class HotelReservationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reservation $reserva,
        public string $asunto,
        public string $cuerpo,
    ) {}

    public function envelope(): Envelope
    {
        $hotel = $this->reserva->hotel;
        $empresa = $this->reserva->company;

        return new Envelope(
            subject: $this->asunto,
            // Se responde al hotel, no a la plataforma: el huésped que
            // contesta este correo quiere hablar con quien le va a abrir la
            // puerta.
            from: $hotel?->email
                ? new Address($hotel->email, $hotel->name)
                : ($empresa?->email ? new Address($empresa->email, $empresa->name) : null),
            replyTo: $hotel?->email ? [new Address($hotel->email, $hotel->name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'hotel.mail.confirmacion',
            with: [
                'reserva' => $this->reserva,
                'hotel' => $this->reserva->hotel,
                'cuerpo' => $this->cuerpo,
            ],
        );
    }
}
