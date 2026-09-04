<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\CustomerAdvance;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Anticipos de clientes: registrarlos y aplicarlos.
 *
 * El anticipo se aplica SOLO a la siguiente factura del cliente. Esa es la
 * regla que impide el descuadre que trae todo sistema de cartera mal atado:
 * un cliente con saldo a favor y saldo en deuda al mismo tiempo, que nadie
 * sabe si debe o le deben.
 *
 * Aplicar no inventa un movimiento nuevo: crea un pago normal contra la
 * factura, con el anticipo como origen. Asi los saldos, los estados y la
 * contabilidad siguen saliendo de un solo sitio.
 */
class CustomerAdvanceService
{
    public function __construct(
        protected SaleInvoiceEngine $invoices,
    ) {}

    /**
     * Registra plata recibida por adelantado.
     *
     * @param  array{date?:string, amount:float|string, payment_method?:string, account_id?:int, reference?:string, notes?:string}  $data
     */
    public function register(ThirdParty $customer, array $data): CustomerAdvance
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('El anticipo debe ser mayor a 0.');
        }

        return DB::transaction(function () use ($customer, $data, $amount) {
            $advance = CustomerAdvance::create([
                'company_id' => $customer->company_id,
                'third_party_id' => $customer->id,
                'date' => $data['date'] ?? now()->toDateString(),
                'amount' => $amount,
                'applied_amount' => 0,
                'payment_method' => $data['payment_method'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);

            // Puede haber facturas pendientes de antes: el anticipo se va
            // contra ellas de una vez, en vez de quedar suelto.
            $this->applyToPendingInvoices($customer);

            return $advance->fresh();
        });
    }

    /**
     * Aplica el saldo a favor del cliente a sus facturas pendientes, de la mas
     * antigua a la mas reciente.
     *
     * Se llama al registrar un anticipo y al contabilizar una factura nueva,
     * que son los dos momentos en que puede quedar saldo a favor sin usar.
     *
     * @return float Cuanto se alcanzo a aplicar.
     */
    public function applyToPendingInvoices(ThirdParty $customer): float
    {
        $disponibles = $this->availableAdvances($customer);

        if ($disponibles->isEmpty()) {
            return 0.0;
        }

        $pendientes = SaleInvoice::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            ->whereIn('payment_status', [
                SaleInvoice::PAYMENT_PENDIENTE,
                SaleInvoice::PAYMENT_PARCIAL,
                SaleInvoice::PAYMENT_VENCIDO,
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->filter(fn (SaleInvoice $i) => $i->isPosted() && (float) $i->balance > 0.01);

        $aplicado = 0.0;

        foreach ($pendientes as $invoice) {
            foreach ($disponibles as $advance) {
                $saldoFactura = round((float) $invoice->fresh()->balance, 2);

                if ($saldoFactura <= 0.01) {
                    break;
                }

                $disponible = $advance->fresh()->available;

                if ($disponible <= 0.01) {
                    continue;
                }

                $monto = min($saldoFactura, $disponible);
                $this->applyAdvanceToInvoice($advance, $invoice, $monto);
                $aplicado += $monto;
            }
        }

        return round($aplicado, 2);
    }

    /**
     * Aplica un anticipo concreto a una factura concreta.
     *
     * Se apoya en el motor de facturas para crear el pago, de modo que el
     * estado de la factura y su asiento salgan de la misma logica que un abono
     * normal. Lo unico propio es marcar de que anticipo vino.
     */
    public function applyAdvanceToInvoice(CustomerAdvance $advance, SaleInvoice $invoice, float $amount): void
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return;
        }

        if ($amount > $advance->available + 0.01) {
            throw new RuntimeException('El anticipo no tiene saldo suficiente para esa aplicación.');
        }

        if ($invoice->third_party_id !== $advance->third_party_id) {
            throw new RuntimeException('El anticipo es de otro cliente.');
        }

        DB::transaction(function () use ($advance, $invoice, $amount) {
            $payment = $this->invoices->addPayment($invoice, [
                'date' => now()->toDateString(),
                'amount' => $amount,
                'payment_method' => 'advance',
                // El dinero ya entro a caja cuando se recibio el anticipo: lo
                // que se mueve ahora es el pasivo contra la cartera.
                'account_id' => $this->advanceAccountId($invoice),
                'reference' => 'Anticipo #'.$advance->id,
                'description' => 'Aplicación de anticipo del '.$advance->date?->format('Y-m-d'),
            ]);

            $payment->update(['customer_advance_id' => $advance->id]);

            $advance->increment('applied_amount', $amount);
        });
    }

    /** Saldo a favor total del cliente. */
    public function availableBalance(ThirdParty $customer): float
    {
        return round((float) $this->availableAdvances($customer)->sum(fn (CustomerAdvance $a) => $a->available), 2);
    }

    /**
     * Anticipos con saldo, del mas antiguo al mas nuevo.
     *
     * @return Collection<int, CustomerAdvance>
     */
    public function availableAdvances(ThirdParty $customer)
    {
        return CustomerAdvance::query()
            ->where('company_id', $customer->company_id)
            ->where('third_party_id', $customer->id)
            ->whereColumn('applied_amount', '<', 'amount')
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cuenta de anticipos recibidos (280505 del PUC).
     *
     * Al aplicarlo se cancela ese pasivo contra la cartera del cliente.
     */
    protected function advanceAccountId(SaleInvoice $invoice): int
    {
        $id = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->whereIn('code', ['280505', '2805'])
            ->orderByRaw('length(code) desc')
            ->value('id');

        if (! $id) {
            throw new RuntimeException(
                'No existe la cuenta de anticipos de clientes (280505). Actívala en el plan de cuentas.'
            );
        }

        return (int) $id;
    }
}
