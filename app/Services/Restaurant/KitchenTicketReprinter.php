<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant\KitchenTicket;

/**
 * Vuelve a mandar una comanda ya generada.
 *
 * Es el caso de "a la cocina no le llego" o "se le mojo el papel": no se
 * regenera el ticket ni se tocan los items, se reimprime el mismo snapshot.
 *
 * El destino depende de como esta configurada la comanda:
 *   - con impresora  -> se reintenta por ESC/POS (red, CUPS o QZ Tray)
 *   - sin impresora  -> la abre el navegador; quien llama recibe browser=true
 *                       y dispara la ventana
 */
class KitchenTicketReprinter
{
    public function __construct(
        protected KitchenTicketPrinter $printer,
    ) {}

    /**
     * @return array{ok:bool, browser:bool, message:string}
     */
    public function reprint(KitchenTicket $ticket): array
    {
        if (! $ticket->restaurant_printer_id) {
            return [
                'ok' => true,
                'browser' => true,
                'message' => 'Se abre en el navegador para imprimir.',
            ];
        }

        $printer = $ticket->printer;

        if (! $printer || ! $printer->active) {
            // La impresora se desactivo o se borro despues de generar la
            // comanda. No es un error del ticket: se saca por navegador.
            return [
                'ok' => true,
                'browser' => true,
                'message' => 'La impresora ya no está disponible; se abre en el navegador.',
            ];
        }

        $ok = $this->printer->print($ticket);

        return [
            'ok' => $ok,
            'browser' => false,
            'message' => $ok
                ? 'Enviada a '.$printer->name.'.'
                : ($ticket->fresh()->error_message ?: 'No se pudo imprimir.'),
        ];
    }
}
