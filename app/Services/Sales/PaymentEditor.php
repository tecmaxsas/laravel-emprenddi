<?php

namespace App\Services\Sales;

use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Support\CashSessionGate;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corregir un cobro ya registrado.
 *
 * **No edita la fila en sitio, y es a proposito.** Un cobro no es un dato
 * suelto: arrastra un asiento contable, el saldo y el estado de pago de su
 * factura, el arqueo del turno y a veces el saldo a favor de un cliente.
 * Cambiarle el monto por debajo con un `update()` dejaria esas cuatro cosas
 * apuntando a una cifra que ya no existe —el asiento seguiria diciendo
 * $400.000 mientras el pago dice $300.000— y nada avisaria.
 *
 * Asi que corregir es reemplazar: se borra el cobro viejo por el camino
 * normal (que reversa su asiento y devuelve lo que haya que devolver) y se
 * registra uno nuevo por el motor de su tipo de factura. El cobro nuevo pasa
 * por exactamente las mismas validaciones que cualquier otro —que no exceda
 * el saldo, que el periodo contable este abierto, que haya caja abierta si es
 * un pago a proveedor— y el viejo queda marcado como borrado, con su motivo,
 * para la auditoria.
 *
 * Todo dentro de una transaccion: si el cobro nuevo no pasa alguna regla, el
 * viejo sigue ahi como estaba. Nunca queda la factura sin ninguno de los dos.
 */
class PaymentEditor
{
    public function __construct(
        private readonly PaymentDeleter $borrador,
    ) {}

    /**
     * Por que no se puede corregir este cobro, o null si si se puede.
     *
     * Separado de `replace()` para que la pantalla pueda apagar el boton con
     * el motivo en el tooltip, en vez de dejar al usuario llenar un formulario
     * para que al final le salga un error.
     */
    public function motivoParaNoEditar(Payment $pago): ?string
    {
        // Corregir empieza por borrar, asi que todo lo que impide borrar
        // impide corregir: un turno cerrado, un cobro ya borrado.
        if ($razon = $this->borrador->motivoParaNoBorrar($pago)) {
            return $razon;
        }

        // Un cobro que salio de un saldo a favor no se puede rehacer aqui: el
        // reemplazo naceria como plata nueva en la caja mientras el anticipo
        // recupera su saldo, y la misma plata quedaria contada dos veces.
        if ($pago->customer_advance_id) {
            return 'Este cobro se pagó con un saldo a favor del cliente. Para corregirlo, '
                .'borra el abono desde el estado de cuenta y vuelve a registrarlo.';
        }

        $factura = $this->borrador->documento($pago);

        if (! $factura) {
            return 'Este cobro no está atado a una factura, así que no se puede volver a registrar.';
        }

        if (! $factura->isPosted()) {
            return 'La factura no está contabilizada.';
        }

        // Pagarle a un proveedor saca plata del cajon, y el motor de compras
        // exige turno abierto para que el cierre lo refleje. Mejor decirlo
        // antes que dejar que reviente despues de borrar.
        if ($factura instanceof PurchaseInvoice && ! CashSessionGate::hasOpenSession()) {
            return 'El pago a un proveedor sale de la caja. Abre una caja registradora '
                .'desde el POS para poder corregirlo.';
        }

        return null;
    }

    /**
     * Que cambia al corregir, en palabras, para la confirmacion.
     *
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    public function cambios(Payment $pago, array $datos): array
    {
        $cambios = [];

        $nuevoMonto = round((float) ($datos['amount'] ?? $pago->amount), 2);

        if (abs($nuevoMonto - (float) $pago->amount) > 0.009) {
            $cambios[] = 'El valor pasa de $'.number_format((float) $pago->amount, 0, ',', '.')
                .' a $'.number_format($nuevoMonto, 0, ',', '.').'.';
        }

        $cambios[] = 'El asiento del cobro actual se reversa y el cobro corregido genera el suyo.';

        return $cambios;
    }

    /**
     * Reemplaza el cobro por uno nuevo con estos datos.
     *
     * @param  array<string, mixed>  $datos  amount, date, payment_method, reference, description, account_id
     *
     * @throws RuntimeException si no se puede corregir o el cobro nuevo no pasa alguna regla
     */
    public function replace(Payment $pago, array $datos, ?string $motivo = null): Payment
    {
        if ($razon = $this->motivoParaNoEditar($pago)) {
            throw new RuntimeException($razon);
        }

        $monto = round((float) ($datos['amount'] ?? 0), 2);

        if ($monto <= 0) {
            throw new RuntimeException('El valor del cobro debe ser mayor que cero.');
        }

        $factura = $this->borrador->documento($pago);
        $metodo = $datos['payment_method'] ?? $pago->payment_method;

        $cuenta = $datos['account_id']
            ?? PaymentAccountResolver::forMethod($metodo, (int) $pago->company_id);

        if (! $cuenta) {
            throw new RuntimeException(
                'No se encontró la cuenta de caja o banco para «'.$metodo.'». '
                .'Configúrala en Métodos de pago.'
            );
        }

        return DB::transaction(function () use ($pago, $factura, $datos, $monto, $metodo, $cuenta, $motivo) {
            $viejo = $pago->id;

            $this->borrador->delete(
                $pago,
                $motivo ?: 'Reemplazado por una corrección',
            );

            $nuevo = [
                'amount' => $monto,
                'date' => $datos['date'] ?? $pago->date?->toDateString() ?? now()->toDateString(),
                'payment_method' => $metodo,
                'account_id' => $cuenta,
                'reference' => $datos['reference'] ?? null,
                // La traza de donde viene: sin esto, quien mire el cobro
                // dentro de seis meses ve uno nuevo y uno borrado sin
                // ninguna relacion entre los dos.
                'description' => trim(($datos['description'] ?? '')."\nCorrige el cobro #{$viejo}."),
            ];

            // El saldo de la factura ya subio al borrar el cobro viejo, asi
            // que `fresh()` es lo que permite que el monto corregido pueda ser
            // mayor que el anterior sin chocar con la validacion del motor.
            return $factura instanceof SaleInvoice
                ? app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), $nuevo)
                : app(PurchaseInvoiceEngine::class)->addPayment($factura->fresh(), $nuevo);
        });
    }
}
