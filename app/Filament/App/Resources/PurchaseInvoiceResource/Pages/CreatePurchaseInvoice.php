<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoiceNumberer;
use App\Support\CashSessionGate;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    /**
     * Vista propia solo para avisar antes de salir.
     *
     * Es una copia de la de Filament con el aviso agregado. Copiar solo el
     * formulario y olvidar el `<form>` y los botones es exactamente lo que dejo
     * la pantalla de ventas sin forma de guardar.
     */
    protected static string $view = 'filament.app.pages.create-purchase-invoice';

    /**
     * La factura nace en borrador, asi que «Crear» ya es guardar el progreso.
     * Se renombra porque el boton no lo decia: quien no lo sabe cree que crear
     * la factura es contabilizarla, y prefiere no tocarla hasta tenerla
     * completa.
     */
    protected function getCreateFormAction(): Actions\Action
    {
        return parent::getCreateFormAction()
            ->label('Guardar borrador')
            ->icon('heroicon-o-bookmark-square');
    }

    protected function getCreateAnotherFormAction(): Actions\Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Guardar y crear otra');
    }

    protected function getSubmitFormAction(): Actions\Action
    {
        return $this->getCreateFormAction();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Borrador guardado';
    }

    /**
     * Preguntar antes de salir con la factura a medio armar.
     *
     * Es el guardian de Filament: compara un hash de los datos del formulario
     * contra el que se guardo al montar, asi que sabe de verdad si algo cambio.
     */
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

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
        $data['kind'] = PurchaseInvoice::KIND_INVOICE;
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
