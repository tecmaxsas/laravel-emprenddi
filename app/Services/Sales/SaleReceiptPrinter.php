<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Restaurant\Printer;
use App\Models\SaleInvoice;
use App\Services\Restaurant\BrowserPrintQueue;
use App\Services\Restaurant\Concerns\SendsEscPos;
use App\Services\Restaurant\EscPosBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Imprime el recibo de una venta del POS tradicional vía ESC/POS.
 * Espejo de RestaurantReceiptPrinter pero sin propinas/mesas — solo
 * cabecera, líneas, totales, pagos y pie. Reutiliza el modelo Printer
 * (App\Models\Restaurant\Printer): la impresora con purpose='cashier'
 * de la sede del invoice es la que recibe el ticket.
 *
 * Flujo:
 *   - connection_type='browser' (QZ Tray) → encola en BrowserPrintQueue;
 *     PosTerminal.flushBrowserPrintJobs() dispara el evento al frontend.
 *   - connection_type='network'/'cups' → envía server-side por TCP/CUPS.
 *   - sin impresora activa → retorna false; el caller cae al HTML imprimible.
 */
class SaleReceiptPrinter
{
    use SendsEscPos;

    /**
     * @param  array<int, array{payment_method?: string, amount?: float|int|string}>  $payments
     */
    public function printReceipt(SaleInvoice $invoice, array $payments = []): bool
    {
        $printer = $this->resolvePrinter($invoice);
        if (! $printer) {
            return false;
        }

        $payload = $this->buildPayload($invoice, $printer, $payments);

        if ($printer->connection_type === 'browser') {
            if (! $printer->printer_name) {
                Log::warning('POS receipt browser printer sin printer_name', [
                    'printer_id' => $printer->id,
                    'name' => $printer->name,
                ]);
                return false;
            }
            app(BrowserPrintQueue::class)->push(
                $printer->printer_name,
                $payload,
                'Factura '.$invoice->fullNumber(),
            );
            return true;
        }

        try {
            return $this->dispatchToPrinter($printer, $payload);
        } catch (\Throwable $e) {
            Log::error('Fallo imprimiendo ticket POS server-side', [
                'invoice_id' => $invoice->id,
                'printer' => $printer->name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Resuelve la impresora 'cashier' activa de la sede del invoice.
     * Si hay varias se toma la primera por id (típicamente solo hay una
     * por sede; si no, el usuario debería desactivar las extras).
     */
    protected function resolvePrinter(SaleInvoice $invoice): ?Printer
    {
        return Printer::query()
            ->where('company_id', $invoice->company_id)
            ->where('location_id', $invoice->location_id)
            ->where('purpose', 'cashier')
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<int, array{payment_method?: string, amount?: float|int|string}>  $payments
     */
    protected function buildPayload(SaleInvoice $invoice, Printer $printer, array $payments): string
    {
        $invoice->loadMissing(['lines', 'customer', 'seller', 'location']);
        $company = Company::find($invoice->company_id);

        $b = new EscPosBuilder((int) ($printer->columns ?: 48));

        // ===== Header =====
        $b->alignCenter()->bold(true)->size(2, 2)
            ->line($company?->name ?? 'Empresa');
        $b->size(1, 1)->bold(false);

        if ($company?->legal_name && $company->legal_name !== $company->name) {
            $b->line($company->legal_name);
        }
        if ($company?->nit) {
            $dv = $company->dv !== null ? '-'.$company->dv : '';
            $b->line('NIT '.$company->nit.$dv);
        }
        if ($company?->address) $b->line($company->address);
        if ($company?->phone) $b->line('Tel: '.$company->phone);
        if ($invoice->location) {
            $b->line('Sede: '.$invoice->location->fullName());
        }

        $b->separator('=');

        // ===== Datos del documento =====
        $b->alignCenter()->bold(true)
            ->line('FACTURA DE VENTA')
            ->line($invoice->fullNumber())
            ->bold(false);
        $date = $invoice->date?->format('Y-m-d') ?? '';
        $hora = $invoice->created_at?->format('H:i') ?? '';
        $b->line(trim($date.' '.$hora));
        $b->separator('-');

        // ===== Cliente =====
        if ($invoice->customer) {
            $b->alignLeft()->bold(true)->line('CLIENTE')->bold(false);
            $b->line($invoice->customer->name);
            $doc = trim(strtoupper((string) $invoice->customer->document_type).' '.$invoice->customer->document_number);
            if ($doc !== '') $b->line($doc);
            $b->separator('-');
        }

        // ===== Líneas =====
        $b->alignLeft();
        foreach ($invoice->lines as $line) {
            $qty = (float) $line->quantity;
            $qtyStr = $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            $name = strtoupper((string) $line->description);
            $b->bold(true)->line($qtyStr.'x '.$name)->bold(false);
            $b->twoCols(
                '  c/u $'.number_format((float) $line->unit_price, 0, ',', '.'),
                '$'.number_format((float) $line->total, 0, ',', '.'),
            );
            if ((float) ($line->discount_amount ?? 0) > 0) {
                $b->twoCols('  Descuento', '-$'.number_format((float) $line->discount_amount, 0, ',', '.'));
            }
        }

        $b->separator('-');

        // ===== Totales =====
        $b->twoCols('Subtotal', '$'.number_format((float) $invoice->subtotal, 0, ',', '.'));
        if ((float) ($invoice->discount_total ?? 0) > 0) {
            $b->twoCols('Descuento', '-$'.number_format((float) $invoice->discount_total, 0, ',', '.'));
        }
        if ((float) ($invoice->tax_total ?? 0) > 0) {
            $b->twoCols('IVA / Impuestos', '$'.number_format((float) $invoice->tax_total, 0, ',', '.'));
        }
        if ((float) ($invoice->retention_total ?? 0) > 0) {
            $b->twoCols('Retenciones', '-$'.number_format((float) $invoice->retention_total, 0, ',', '.'));
        }
        $b->separator('=');
        $b->bold(true)->size(1, 2)
            ->twoCols('TOTAL', '$'.number_format((float) ($invoice->net_payable ?? $invoice->total), 0, ',', '.'))
            ->size(1, 1)->bold(false);
        $b->separator('=');

        // ===== Pagos =====
        if (! empty($payments)) {
            $b->line('FORMA DE PAGO:');
            foreach ($payments as $p) {
                $method = $p['payment_method'] ?? 'cash';
                $label = \App\Models\Payment::PAYMENT_METHODS[$method] ?? ucfirst($method);
                $b->twoCols('  '.$label, '$'.number_format((float) ($p['amount'] ?? 0), 0, ',', '.'));
            }
            if ((float) ($invoice->paid_amount ?? 0) > 0) {
                $b->bold(true)->twoCols('PAGADO',
                    '$'.number_format((float) $invoice->paid_amount, 0, ',', '.'))->bold(false);
            }
            $b->separator('-');
        }

        // ===== Footer =====
        $b->alignCenter();
        if ($invoice->seller) {
            $b->line('Atendido por: '.$invoice->seller->name);
        }
        $b->lf()->bold(true)->line('Gracias por tu compra')->bold(false)->lf();

        $b->cut();

        if ($printer->open_cash_drawer) {
            $b->openDrawer();
        }

        return $b->getBytes();
    }
}
