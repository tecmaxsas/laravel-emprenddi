<?php

namespace App\Services\Restaurant;

use App\Models\Company;
use App\Models\Payment;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Printer;
use App\Models\SaleInvoice;
use App\Services\Restaurant\Concerns\SendsEscPos;
use Illuminate\Support\Facades\Log;

/**
 * Imprime el recibo / factura del cliente por ESC/POS en la impresora
 * con propósito 'cashier' de la sede. Se dispara al cobrar una orden.
 *
 * Si no hay impresora de caja configurada, no hace nada (no rompe el
 * flujo de cobro). Si falla la impresión, loguea pero tampoco aborta.
 */
class RestaurantReceiptPrinter
{
    use SendsEscPos;

    /**
     * @param  array  $payments  [['payment_method'=>.., 'amount'=>..], ...]
     */
    public function printReceipt(
        SaleInvoice $invoice,
        Order $order,
        float $tipShare = 0,
        array $payments = [],
        ?string $tabLabel = null,
    ): bool {
        $printer = Printer::query()
            ->where('company_id', $order->company_id)
            ->where('location_id', $order->location_id)
            ->where('purpose', 'cashier')
            ->where('active', true)
            ->first();

        if (! $printer) {
            // Sin impresora de caja: el recibo no se imprime, pero la venta
            // ya está registrada. El cajero puede imprimir desde Facturas.
            return false;
        }

        try {
            $payload = $this->buildPayload($invoice, $order, $printer, $tipShare, $payments, $tabLabel);

            // Modo browser: encolar para QZ Tray en vez de imprimir server-side.
            if ($printer->connection_type === 'browser') {
                if (! $printer->printer_name) {
                    Log::warning('Receipt browser printer sin printer_name', ['printer' => $printer->name]);
                    return false;
                }
                app(BrowserPrintQueue::class)->push(
                    $printer->printer_name,
                    $payload,
                    'Factura '.$invoice->fullNumber(),
                );
                return true;
            }

            return $this->dispatchToPrinter($printer, $payload);
        } catch (\Throwable $e) {
            Log::warning('Restaurant receipt print failed', [
                'invoice_id' => $invoice->id,
                'printer' => $printer->name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function buildPayload(
        SaleInvoice $invoice,
        Order $order,
        Printer $printer,
        float $tipShare,
        array $payments,
        ?string $tabLabel,
    ): string {
        $b = new EscPosBuilder((int) $printer->columns);
        $company = Company::find($invoice->company_id);
        $invoice->loadMissing('lines');

        // ===== Header =====
        $b->alignCenter()->bold(true)->size(2, 2)
            ->line($company?->name ?? 'Restaurante');
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

        $b->separator('=');

        // ===== Datos de la factura =====
        $b->alignLeft()->bold(true)
            ->line('FACTURA '.$invoice->fullNumber())
            ->bold(false)
            ->line('Fecha: '.now()->format('Y-m-d H:i:s'));

        $tableLabel = $order->table?->code
            ?? ($order->is_takeaway ? 'Para llevar' : ($order->is_delivery ? 'Domicilio' : 'Sin mesa'));
        $b->line('Mesa: '.$tableLabel);
        if ($tabLabel) {
            $b->line('Tab: '.$tabLabel);
        }
        $b->line('Mesero: '.($order->server?->name ?? '—'));
        $b->line('Atendido: '.$order->fullNumber());

        // Datos de entrega: sin esto el repartidor sale con el tiquete pero
        // sin saber a donde va.
        if ($order->is_delivery) {
            $entrega = $order->delivery_metadata ?? [];

            $b->separator('-')->bold(true)->line('DOMICILIO')->bold(false);

            if (! empty($entrega['customer_name'])) {
                $b->line($entrega['customer_name']);
            }
            if (! empty($entrega['customer_document'])) {
                $b->line('CC '.$entrega['customer_document']);
            }
            if (! empty($entrega['address'])) {
                $b->line($entrega['address']);
            }
            if (! empty($entrega['address_notes'])) {
                $b->line('Ref: '.$entrega['address_notes']);
            }
            if (! empty($entrega['customer_phone'])) {
                $b->line('Tel: '.$entrega['customer_phone']);
            }
            if (! empty($entrega['driver_name'])) {
                $b->line('Repartidor: '.$entrega['driver_name']);
            }
            if ((float) $order->delivery_fee > 0) {
                $b->line('Domicilio: $'.number_format((float) $order->delivery_fee, 0, ',', '.'));
            }
        }

        $b->separator('-');

        // ===== Items =====
        foreach ($invoice->lines as $line) {
            $qty = (float) $line->quantity;
            $name = strtoupper((string) $line->description);
            // Linea 1: cantidad x nombre
            $b->bold(true)->line(rtrim(number_format($qty, 0).'x '.$name))->bold(false);
            // Linea 2: precio total alineado a la derecha
            $b->twoCols('  c/u $'.number_format((float) $line->unit_price, 0, ',', '.'),
                        '$'.number_format((float) $line->total, 0, ',', '.'));
        }

        $b->separator('-');

        // ===== Totales =====
        $b->twoCols('Subtotal', '$'.number_format((float) $invoice->subtotal, 0, ',', '.'));
        if ((float) $invoice->tax_total > 0) {
            $b->twoCols('IVA / Impuestos', '$'.number_format((float) $invoice->tax_total, 0, ',', '.'));
        }
        if ($tipShare > 0) {
            $b->twoCols('Propina', '$'.number_format($tipShare, 0, ',', '.'));
        }

        $b->separator('=');
        $grandTotal = (float) $invoice->total + $tipShare;
        $b->bold(true)->size(1, 2)
            ->twoCols('TOTAL', '$'.number_format($grandTotal, 0, ',', '.'))
            ->size(1, 1)->bold(false);
        $b->separator('=');

        // ===== Pagos =====
        if (! empty($payments)) {
            $b->line('FORMA DE PAGO:');
            foreach ($payments as $p) {
                $methodLabel = Payment::PAYMENT_METHODS[$p['payment_method'] ?? 'cash'] ?? ($p['payment_method'] ?? 'Efectivo');
                $b->twoCols('  '.$methodLabel, '$'.number_format((float) ($p['amount'] ?? 0), 0, ',', '.'));
            }
            $b->separator('-');
        }

        // ===== Footer =====
        $b->alignCenter()
            ->lf()
            ->bold(true)->line('¡Gracias por su visita!')->bold(false)
            ->line('Te esperamos pronto')
            ->lf();

        $b->cut();

        // Abrir cajón de monedas si la impresora de caja lo tiene activado
        if ($printer->open_cash_drawer) {
            $b->openDrawer();
        }

        return $b->getBytes();
    }
}
