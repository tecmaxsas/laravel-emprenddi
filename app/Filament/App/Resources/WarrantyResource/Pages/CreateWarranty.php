<?php

namespace App\Filament\App\Resources\WarrantyResource\Pages;

use App\Filament\App\Resources\WarrantyResource;
use App\Models\Company;
use App\Services\Warranties\WarrantyEngine;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Crea el ticket pasando por WarrantyEngine para que dispare el cálculo
 * de expiration_date y el evento "created". No se llama a Eloquent::create
 * directamente — pasar por el engine garantiza que el timeline siempre
 * tenga un primer evento.
 */
class CreateWarranty extends CreateRecord
{
    protected static string $resource = WarrantyResource::class;

    /**
     * Si la URL trae ?serial=<id>, precarga los campos del serial vendido
     * para que el usuario solo digite el motivo. Atajo desde ViewProductSerial.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $serialId = request()->query('serial');
        if (! $serialId) return $data;

        $serial = \App\Models\ProductSerial::query()
            ->whereKey($serialId)
            ->with(['saleLine.invoice', 'product'])
            ->first();
        if (! $serial) return $data;

        $data['product_serial_id'] = $serial->id;
        $data['search_serial'] = $serial->serial_number;
        $data['product_id'] = $serial->product_id;
        $data['location_id'] = $serial->location_id;
        if ($invoice = $serial->saleLine?->invoice) {
            $data['sale_invoice_id'] = $invoice->id;
            $data['third_party_id'] = $invoice->third_party_id;
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): \App\Models\Warranty
    {
        $company = Company::find(auth()->user()->company_id);
        $warranty = app(WarrantyEngine::class)->create($company, $data);

        Notification::make()
            ->success()
            ->title('Garantía '.$warranty->fullNumber().' creada')
            ->body('Estado inicial: Recibida.')
            ->send();

        return $warranty;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
