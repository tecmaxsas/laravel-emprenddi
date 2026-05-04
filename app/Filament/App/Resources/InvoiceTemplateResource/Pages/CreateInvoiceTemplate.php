<?php

namespace App\Filament\App\Resources\InvoiceTemplateResource\Pages;

use App\Filament\App\Resources\InvoiceTemplateResource;
use App\Models\InvoiceTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceTemplate extends CreateRecord
{
    protected static string $resource = InvoiceTemplateResource::class;

    /**
     * Llena keys faltantes con defaultSettings antes de persistir, para que
     * un template recién creado tenga todos los toggles definidos aunque la UI
     * solo haya tocado algunos.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['settings'] = array_replace_recursive(
            InvoiceTemplate::defaultSettings(),
            $data['settings'] ?? [],
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        InvoiceTemplateResource::ensureSingleDefault($this->record);
    }
}
