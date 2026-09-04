<?php

namespace App\Services\Sales;

use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use RuntimeException;

/**
 * Cupo de credito del cliente.
 *
 * El campo existia desde el principio pero nadie lo miraba, asi que se podia
 * seguir despachando a credito a quien ya debia de mas. Ahora se revisa al
 * contabilizar la factura, que es el momento en que la venta se vuelve cartera
 * de verdad.
 *
 * Un cupo en 0 significa SIN LIMITE, no "no puede comprar a credito". Es como
 * viene la mayoria de terceros y como lo muestra el sistema del que migran; si
 * 0 bloqueara, activar la validacion dejaria a toda la base sin poder vender.
 */
class CustomerCreditGuard
{
    public function __construct(
        protected CustomerAdvanceService $advances,
    ) {}

    /**
     * Corta la venta a credito que deja al cliente por encima de su cupo.
     *
     * Solo aplica a lo que queda debiendo: una factura pagada de contado no
     * consume cupo por mucho que valga.
     */
    public function assertWithinLimit(SaleInvoice $invoice): void
    {
        $customer = $invoice->customer;

        if (! $customer || ! $this->hasLimit($customer)) {
            return;
        }

        $porCobrar = round((float) ($invoice->net_payable ?: $invoice->total) - (float) $invoice->paid_amount, 2);

        if ($porCobrar <= 0.01) {
            return;
        }

        $cupo = round((float) $customer->credit_limit, 2);
        $deuda = $this->currentDebt($customer, exceptInvoiceId: $invoice->id);
        $quedaria = round($deuda + $porCobrar, 2);

        if ($quedaria <= $cupo + 0.01) {
            return;
        }

        throw new RuntimeException(sprintf(
            'La venta a crédito deja a %s en $%s y su cupo es de $%s. Ya debe $%s, y esta factura suma $%s. '
            .'Registra un abono o amplía el cupo del cliente antes de contabilizar.',
            $customer->name,
            number_format($quedaria, 0, ',', '.'),
            number_format($cupo, 0, ',', '.'),
            number_format($deuda, 0, ',', '.'),
            number_format($porCobrar, 0, ',', '.'),
        ));
    }

    /** Cuanto le queda de cupo al cliente. Null si no tiene limite. */
    public function availableCredit(ThirdParty $customer): ?float
    {
        if (! $this->hasLimit($customer)) {
            return null;
        }

        return round((float) $customer->credit_limit - $this->currentDebt($customer), 2);
    }

    /**
     * Lo que el cliente debe hoy: saldo de apertura mas facturas pendientes,
     * menos el saldo a favor que todavia no se ha aplicado.
     */
    public function currentDebt(ThirdParty $customer, ?int $exceptInvoiceId = null): float
    {
        $pendiente = SaleInvoice::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            ->whereIn('payment_status', [
                SaleInvoice::PAYMENT_PENDIENTE,
                SaleInvoice::PAYMENT_PARCIAL,
                SaleInvoice::PAYMENT_VENCIDO,
            ])
            ->when($exceptInvoiceId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->get()
            ->filter(fn (SaleInvoice $i) => $i->isPosted())
            ->sum(fn (SaleInvoice $i) => (float) $i->balance);

        $deuda = (float) $customer->opening_balance
            + $pendiente
            - $this->advances->availableBalance($customer);

        // Un cliente con saldo a favor no tiene deuda negativa: tiene cupo
        // completo. Restarle mas alla de cero le regalaria cupo de mas.
        return round(max(0, $deuda), 2);
    }

    private function hasLimit(ThirdParty $customer): bool
    {
        return (float) $customer->credit_limit > 0;
    }
}
