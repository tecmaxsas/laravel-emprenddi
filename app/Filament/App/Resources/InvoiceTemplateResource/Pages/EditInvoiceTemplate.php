<?php

namespace App\Filament\App\Resources\InvoiceTemplateResource\Pages;

use App\Filament\App\Resources\InvoiceTemplateResource;
use App\Models\InvoiceTemplate;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvoiceTemplate extends EditRecord
{
    protected static string $resource = InvoiceTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = array_replace_recursive(
            InvoiceTemplate::defaultSettings(),
            $data['settings'] ?? [],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        InvoiceTemplateResource::ensureSingleDefault($this->record);
    }
}
