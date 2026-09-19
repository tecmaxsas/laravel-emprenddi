<?php

namespace App\Support;

/**
 * Cuánto hay que devolverle al cliente.
 *
 * Es una resta, y por eso mismo conviene que esté escrita una sola vez: los
 * tres POS —retail, restaurante y parqueadero— hacen exactamente lo mismo, y
 * una resta copiada en tres sitios es una resta que algún día se redondea
 * distinto en uno de ellos.
 *
 * Reglas, que no son obvias:
 *
 *  - **Solo el efectivo tiene vuelto.** Nadie devuelve plata de una
 *    transferencia ni de una tarjeta: se cobra el monto exacto. Preguntar
 *    «cuánto recibió» en esos métodos es una casilla más que llenar sin razón.
 *  - **Si entregó menos, no hay vuelto negativo.** Eso no es un vuelto, es un
 *    pago incompleto, y el POS tiene que decirlo como tal en vez de mostrar
 *    un número en rojo que nadie sabe interpretar.
 *  - **El vuelto no es una salida de caja.** El billete entra completo y sale
 *    la diferencia; lo que queda en el cajón es el monto cobrado. Por eso el
 *    arqueo suma `amount` y no `cash_received`.
 */
class Vuelto
{
    /**
     * Lo que hay que devolver, o 0 si no hay nada que devolver.
     *
     * Se redondea a peso: en Colombia no circulan centavos, y devolver
     * «$1.333,33» es una cifra que el cajero no puede entregar.
     */
    public static function calcular(float $recibido, float $aPagar): float
    {
        return round(max(0, $recibido - $aPagar), 0);
    }

    /** Falta plata para completar el pago, y cuánta. */
    public static function faltante(float $recibido, float $aPagar): float
    {
        return round(max(0, $aPagar - $recibido), 0);
    }

    /**
     * Si tiene sentido preguntar cuánto entregó el cliente.
     *
     * Solo en efectivo, y se resuelve por el **tipo** del método y no por su
     * código: una empresa puede tener su propio «Efectivo caja 2».
     */
    public static function aplica(?string $metodo, ?int $companyId = null): bool
    {
        return PaymentMethodOptions::esEfectivo($metodo, $companyId);
    }

    /**
     * Lo que se guarda en el pago, ya resuelto.
     *
     * Devuelve null en las dos columnas cuando no aplica —un cobro con tarjeta
     * no tiene entrega ni vuelto, y guardar ceros ahí haría creer que sí los
     * hubo—.
     *
     * @return array{cash_received: float|null, change_given: float|null}
     */
    public static function paraGuardar(
        ?string $metodo,
        float|string|null $recibido,
        float $aPagar,
        ?int $companyId = null,
    ): array {
        $vacio = ['cash_received' => null, 'change_given' => null];

        if (! self::aplica($metodo, $companyId)) {
            return $vacio;
        }

        $recibido = (float) ($recibido ?? 0);

        // Sin dato, o por debajo de lo que cuesta, no hay nada que contar: el
        // cajero cobró el monto justo y no digitó la entrega.
        if ($recibido <= 0 || $recibido < $aPagar) {
            return $vacio;
        }

        return [
            'cash_received' => round($recibido, 2),
            'change_given' => self::calcular($recibido, $aPagar),
        ];
    }
}
