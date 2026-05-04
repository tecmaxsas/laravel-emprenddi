<?php

namespace App\Http\Controllers;

use App\Models\InvoiceTemplate;
use App\Models\SaleInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Render del ticket de venta para impresión.
 * URL: /app/pos/print/{invoice}
 *
 * Usa el InvoiceTemplate asignado a la sede de la factura — toggles del
 * settings JSON deciden qué se muestra (logo, NIT, IVA, QR DIAN, etc.).
 * Auto-print al cargar; ventana se cierra después de imprimir.
 */
class PosPrintController extends Controller
{
    public function show(int $invoice): View
    {
        $invoice = SaleInvoice::query()
            ->with(['lines.product', 'customer', 'location.invoiceTemplate', 'seller', 'company', 'payments'])
            ->where('id', $invoice)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        abort_unless(Auth::user()->can('pos.use'), 403);

        $template = $invoice->location?->resolveInvoiceTemplate()
            ?? InvoiceTemplate::query()
                ->where('company_id', $invoice->company_id)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->first();

        return view('pos.print-ticket', [
            'invoice' => $invoice,
            'template' => $template,
            'paperSize' => $template?->paper_size ?? 'pos_80',
            'settings' => $template?->settings ?? InvoiceTemplate::defaultSettings(),
            'footerText' => $template?->footer_text,
        ]);
    }
}
