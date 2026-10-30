<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Reservation;
use App\Support\ClockFormat;
use App\Support\WhatsappNumero;

/**
 * El texto de la confirmación que se le manda al huésped.
 *
 * Vive en un solo sitio porque sale por dos caminos —WhatsApp y correo— y los
 * dos tienen que decir lo mismo. Escrito dos veces, un día el correo lleva la
 * hora de entrada y el WhatsApp no, y el huésped llega a las diez de la mañana
 * a una habitación que se entrega a las tres.
 *
 * Qué lleva y por qué:
 *
 *  - **El código.** Es lo que el huésped va a decir por teléfono cuando llame
 *    a preguntar o a cambiar algo.
 *  - **Las fechas y las noches**, escritas de las dos formas. «Del 8 al 10»
 *    es ambiguo para medio mundo —¿dos noches o tres?— y decir «2 noches» al
 *    lado lo resuelve sin discusión en el mostrador.
 *  - **Las horas de entrada y de salida.** La causa número uno de un roce en
 *    recepción es alguien que llega a mediodía esperando entrar.
 *  - **El total estimado**, dicho como estimado: los consumos todavía no
 *    existen.
 *  - **Qué pasa con el depósito**, si lo hay.
 */
class HotelConfirmacion
{
    /** El mensaje completo, en texto plano. Sirve para WhatsApp y para correo. */
    public function mensaje(Reservation $reserva): string
    {
        $hotel = $reserva->hotel;
        $plata = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');

        $nombre = trim(explode(' ', trim($reserva->nombreDelHuesped()))[0] ?: 'buen día');

        $lineas = [];

        $lineas[] = "Hola {$nombre}, le confirmamos su reserva en {$hotel?->name}.";
        $lineas[] = '';
        $lineas[] = "Código: {$reserva->code}";
        $lineas[] = 'Entrada: '.$reserva->check_in_date->format('d/m/Y')
            .' desde las '.($hotel?->checkInTime() ?? '15:00');
        $lineas[] = 'Salida: '.$reserva->check_out_date->format('d/m/Y')
            .' hasta las '.($hotel?->checkOutTime() ?? '12:00');
        $lineas[] = 'Noches: '.$reserva->nights;

        if ($reserva->roomType) {
            $lineas[] = 'Habitación: '.$reserva->roomType->name;
        }

        $personas = $reserva->adults.' adulto'.($reserva->adults === 1 ? '' : 's');

        if ($reserva->children > 0) {
            $personas .= ' y '.$reserva->children.' niño'.($reserva->children === 1 ? '' : 's');
        }

        $lineas[] = 'Para: '.$personas;

        if ($reserva->mealPlan) {
            $lineas[] = 'Plan: '.$reserva->mealPlan->name;
        }

        $lineas[] = '';
        $lineas[] = 'Valor estimado del alojamiento: '.$plata($reserva->estimated_total);

        $recibido = $reserva->depositoRecibido();

        if ($recibido > 0) {
            $lineas[] = 'Ya recibimos su depósito de '.$plata($recibido)
                .', que se descuenta al momento de pagar.';
        } elseif ((float) $reserva->deposit_amount > 0) {
            $lineas[] = 'Para dejar la reserva en firme necesitamos un depósito de '
                .$plata($reserva->deposit_amount).'.';
        }

        $lineas[] = '';
        $lineas[] = 'El valor final puede variar según los consumos de la estadía.';

        $contacto = array_filter([
            $hotel?->address,
            $hotel?->phone ? 'Tel. '.$hotel->phone : null,
        ]);

        if ($contacto !== []) {
            $lineas[] = '';
            $lineas[] = implode(' · ', $contacto);
        }

        return implode("\n", $lineas);
    }

    /** El asunto del correo. */
    public function asunto(Reservation $reserva): string
    {
        return sprintf(
            'Confirmación de su reserva %s — %s',
            $reserva->code,
            $reserva->hotel?->name ?? '',
        );
    }

    /**
     * El enlace de WhatsApp, o null si el teléfono no sirve.
     *
     * Null y no un enlace roto: es preferible no mostrar el botón a abrir un
     * chat con un número que no existe, porque eso el recepcionista lo
     * interpreta como «ya quedó enviado».
     */
    public function enlaceWhatsapp(Reservation $reserva): ?string
    {
        return WhatsappNumero::enlace($reserva->guest_phone, $this->mensaje($reserva));
    }

    public function puedeEnviarPorCorreo(Reservation $reserva): bool
    {
        return filter_var((string) $reserva->guest_email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Para el pie del correo, con el formato de hora de la empresa. */
    public function enviadaEl(): string
    {
        return now()->format(ClockFormat::datetime());
    }
}
