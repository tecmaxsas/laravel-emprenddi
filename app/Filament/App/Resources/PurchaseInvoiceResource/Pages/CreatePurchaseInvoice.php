<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Models\Company;
use App\Services\Purchases\PurchaseInvoiceNumberer;
use App\Support\CashSessionGate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    /**
     * Bloquea la creación de compras si el operador no tiene caja abierta.
     * Misma regla que ventas POS: cualquier ingreso o egreso del turno
     * debe quedar atado a una sesión para que el cierre cuadre.
     */
    public function mount(): void
    {
        if (! CashSessionGate::hasOpenSession()) {
            Notification::make()
                ->title('Necesitas una caja abierta')
                ->body('Para registrar una compra debes abrir primero la caja registradora desde el POS.')
                ->warning()
                ->persistent()
                ->send();

            $this->redirect(PurchaseInvoiceResource::getUrl('index'));
            return;
        }

        parent::mount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $session = CashSessionGate::requireOpenSession();

        $data['company_id'] = Auth::user()->company_id;
        $data['kind'] = \App\Models\PurchaseInvoice::KIND_INVOICE;
        $data['cash_register_session_id'] = $session->id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';
        $data['payment_status'] = 'pendiente';

        // Auto-numeración usando el numerador de COMPRAS (no journal entries).
        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'FC';
        $data['number'] = app(PurchaseInvoiceNumberer::class)->next($company, $prefix);

        // Recompute totals desde las líneas (defensa por si las hidden no se llenaron)
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        $lineNum = 1;

        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum, &$subtotal, &$discount, &$tax, &$total) {
            $line['line_number'] = $lineNum++;
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $discount += (float) ($line['discount_amount'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);

            return $line;
        })->all();

        $data['subtotal'] = $subtotal;
        $data['discount_total'] = $discount;
        $data['tax_total'] = $tax;
        $data['total'] = $total;

        return $data;
    }
}
