<?php

namespace App\Services\Sales;

use App\Models\CreditDebitNote;
use App\Models\CustomerAdvance;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use Illuminate\Support\Collection;

/**
 * Hoja de cuenta de un cliente: sus movimientos y el saldo despues de cada uno.
 *
 * Se calcula leyendo las facturas y los pagos, NO una tabla de movimientos
 * aparte. Es a proposito: una bitacora paralela puede desincronizarse de los
 * documentos y entonces el estado de cuenta miente sin que nada avise. Asi el
 * saldo que se muestra siempre es el que sale de los documentos.
 *
 * Debito suma a lo que el cliente debe; credito lo baja.
 */
class CustomerStatement
{
    public const OPENING = 'apertura';

    public const INVOICE = 'factura';

    public const PAYMENT = 'abono';

    public const ADVANCE = 'anticipo';

    /**
     * Aplicacion de un anticipo a una deuda.
     *
     * Existe para que el anticipo se pueda mostrar por su valor COMPLETO. Antes
     * se mostraba solo la parte sin usar: un anticipo de $2.000.000 del que se
     * habian aplicado $1.500.000 aparecia como «$500.000», y el cliente no
     * encontraba por ningun lado los dos millones que entrego. Ahora entra
     * entero como credito y cada aplicacion sale como el debito que lo
     * consume, que es como se lee una cuenta: el movimiento y su contrapartida.
     */
    public const APPLICATION = 'aplicacion';

    /**
     * Devoluciones: nota credito electronica o devolucion POS.
     *
     * Faltaban en el estado de cuenta, y era grave: el cliente devolvia la
     * mercancia, los libros lo reconocian, y el documento que se le muestra al
     * cliente seguia cobrandole todo. La conversacion que sale de eso no se
     * arregla con una explicacion.
     */
    public const NOTE = 'nota';

    public function __construct(
        protected CustomerAdvanceService $advances,
    ) {}

    /**
     * @return array{
     *   customer: ThirdParty,
     *   from: ?string, to: ?string,
     *   movements: Collection<int, array<string, mixed>>,
     *   opening_balance: float, invoiced: float, paid: float,
     *   advance_balance: float, due: float
     * }
     */
    public function build(ThirdParty $customer, ?string $desde = null, ?string $hasta = null): array
    {
        $movimientos = $this->movimientos($customer, $desde, $hasta);

        $saldo = 0.0;
        $movimientos = $movimientos->map(function (array $m) use (&$saldo) {
            $saldo = round($saldo + $m['debit'] - $m['credit'], 2);
            $m['balance'] = $saldo;

            return $m;
        });

        $facturado = round((float) $movimientos->where('type', self::INVOICE)->sum('debit'), 2);
        $pagado = round((float) $movimientos->whereIn('type', [self::PAYMENT])->sum('credit'), 2);

        return [
            'customer' => $customer,
            'from' => $desde,
            'to' => $hasta,
            'movements' => $movimientos,
            'opening_balance' => round((float) $customer->opening_balance, 2),
            'invoiced' => $facturado,
            'paid' => $pagado,
            // Lo que el cliente tiene a favor sin aplicar. Con la aplicación
            // automática debería ser 0 mientras tenga facturas pendientes.
            'advance_balance' => $this->advances->availableBalance($customer),
            'due' => $saldo,
        ];
    }

    /**
     * Movimientos ordenados por fecha.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function movimientos(ThirdParty $customer, ?string $desde, ?string $hasta): Collection
    {
        $movimientos = collect();

        // El saldo de apertura es lo que el cliente ya debía cuando la empresa
        // empezó a usar Emprenddi. Va siempre primero, aunque haya filtro de
        // fechas: sin él el saldo corrido no significa nada.
        if ($customer->tieneSaldoDeApertura()) {
            // Por su valor ORIGINAL: es lo que el cliente debía el día de la
            // migración, y es el movimiento que abre la hoja. Lo que se le
            // haya abonado después sale como su propio crédito, debajo.
            $movimientos->push([
                'date' => $customer->opening_balance_date?->toDateString() ?? '',
                'type' => self::OPENING,
                'reference' => 'Saldo de apertura',
                'description' => 'Saldo traído del sistema anterior',
                'debit' => round((float) $customer->opening_balance, 2),
                'credit' => 0.0,
            ]);

            $abonado = round((float) $customer->opening_balance_applied, 2);

            if ($abonado > 0.009) {
                $movimientos->push([
                    'date' => $customer->opening_balance_date?->toDateString() ?? '',
                    'type' => self::APPLICATION,
                    'reference' => 'Abono a saldo de apertura',
                    'description' => 'Anticipo aplicado a la deuda anterior',
                    'debit' => 0.0,
                    'credit' => $abonado,
                ]);
            }
        }

        foreach ($this->facturas($customer, $desde, $hasta) as $invoice) {
            $movimientos->push([
                'date' => $invoice->date?->toDateString() ?? '',
                'type' => self::INVOICE,
                'reference' => $invoice->fullNumber(),
                'description' => 'Venta'.($invoice->due_date ? ' — vence '.$invoice->due_date->format('Y-m-d') : ''),
                // El neto a pagar, no el total: las retenciones que nos hizo el
                // cliente no son saldo suyo.
                'debit' => round((float) $invoice->net_payable, 2),
                'credit' => 0.0,
                'invoice' => $invoice,
            ]);
        }

        foreach ($this->pagos($customer, $desde, $hasta) as $payment) {
            $esAnticipo = $payment->customer_advance_id !== null;

            $movimientos->push([
                'date' => $payment->date?->toDateString() ?? '',
                'type' => self::PAYMENT,
                'reference' => $payment->paymentable?->fullNumber() ?? '—',
                'description' => $esAnticipo
                    ? 'Aplicación de anticipo'
                    : 'Abono'.($payment->payment_method ? ' — '.$payment->payment_method : ''),
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 2),
                // Para que el estado de cuenta pueda ofrecer corregir o borrar
                // el cobro sin obligar a ir a buscar la factura.
                'payment_id' => $payment->id,
            ]);
        }

        foreach ($this->notas($customer, $desde, $hasta) as $note) {
            $movimientos->push([
                'date' => $note->date?->toDateString() ?? '',
                'type' => self::NOTE,
                'reference' => $note->fullNumber(),
                'description' => $note->esPos()
                    ? 'Devolución POS'
                    : 'Nota crédito'.($note->reason_description ? ' — '.$note->reason_description : ''),
                'debit' => 0.0,
                // Lo que se devolvio en efectivo no baja la cartera: esa plata
                // ya salio del cajon. Aparece igual, porque el cliente tiene
                // que ver que se le reconocio la devolucion.
                'credit' => $note->devuelvePlata() ? 0.0 : round((float) $note->total, 2),
            ]);
        }

        foreach ($this->anticipos($customer, $desde, $hasta) as $advance) {
            // El anticipo ENTERO, no solo lo que queda sin usar.
            //
            // Antes se mostraba `available`: un anticipo de $2.000.000 con
            // $1.500.000 ya aplicados aparecía como «$500.000» y el cliente no
            // encontraba sus dos millones por ningún lado. El saldo corrido
            // salía bien, pero el documento no explicaba nada.
            $movimientos->push([
                'date' => $advance->date?->toDateString() ?? '',
                'type' => self::ADVANCE,
                'reference' => 'Anticipo #'.$advance->id,
                'description' => 'Pago anticipado'.($advance->reference ? ' — '.$advance->reference : ''),
                'debit' => 0.0,
                'credit' => round((float) $advance->amount, 2),
            ]);

            // Y su contrapartida: lo aplicado sale como débito, que es lo que
            // consume el anticipo. El par suma cero, así que el saldo corrido
            // no cambia — lo que cambia es que ahora se puede seguir la pista
            // de cada peso.
            $aplicado = round((float) $advance->applied_amount, 2);

            if ($aplicado > 0.009) {
                $movimientos->push([
                    'date' => $advance->date?->toDateString() ?? '',
                    'type' => self::APPLICATION,
                    'reference' => 'Aplicación anticipo #'.$advance->id,
                    'description' => 'Aplicado a la deuda del cliente',
                    'debit' => $aplicado,
                    'credit' => 0.0,
                ]);
            }
        }

        // El saldo de apertura primero; el resto por fecha, y a igual fecha la
        // factura antes que el pago: se cobra lo que ya se debe.
        return $movimientos
            ->sortBy([
                fn (array $a, array $b) => ($a['type'] === self::OPENING ? 0 : 1) <=> ($b['type'] === self::OPENING ? 0 : 1),
                fn (array $a, array $b) => $a['date'] <=> $b['date'],
                fn (array $a, array $b) => ($a['type'] === self::INVOICE ? 0 : 1) <=> ($b['type'] === self::INVOICE ? 0 : 1),
            ])
            ->values();
    }

    /** @return Collection<int, SaleInvoice> */
    protected function facturas(ThirdParty $customer, ?string $desde, ?string $hasta): Collection
    {
        return SaleInvoice::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            ->where('payment_status', '!=', SaleInvoice::PAYMENT_CANCELADA)
            ->when($desde, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($hasta, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->orderBy('date')
            ->get()
            ->filter(fn (SaleInvoice $i) => $i->isPosted());
    }

    /** @return Collection<int, Payment> */
    protected function pagos(ThirdParty $customer, ?string $desde, ?string $hasta): Collection
    {
        return Payment::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            ->where('paymentable_type', SaleInvoice::class)
            ->when($desde, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($hasta, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->with('paymentable')
            ->orderBy('date')
            ->get();
    }

    /**
     * Solo los anticipos que todavia tienen saldo.
     *
     * El que ya se aplico entero aparece como el pago que genero, no dos
     * veces: contarlo aqui tambien restaria el doble del saldo.
     *
     * @return Collection<int, CustomerAdvance>
     */
    /**
     * Las devoluciones contabilizadas del cliente.
     *
     * @return Collection<int, CreditDebitNote>
     */
    protected function notas(ThirdParty $customer, ?string $desde, ?string $hasta): Collection
    {
        return CreditDebitNote::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            ->where('type', CreditDebitNote::TYPE_CREDIT)
            ->where('status', CreditDebitNote::STATUS_POSTED)
            ->when($desde, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($hasta, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->orderBy('date')
            ->get();
    }

    protected function anticipos(ThirdParty $customer, ?string $desde, ?string $hasta): Collection
    {
        return CustomerAdvance::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            // TODOS, no solo los que tienen saldo sin usar: un anticipo
            // aplicado por completo tambien es un movimiento de la cuenta y
            // tiene que poder verse.
            ->when($desde, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($hasta, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->orderBy('date')
            ->get();
    }
}
