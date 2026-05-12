<?php

namespace App\Filament\App\Resources\InventoryOpeningResource\Pages;

use App\Filament\App\Resources\InventoryOpeningResource;
use App\Models\InventoryOpening;
use Filament\Resources\Pages\EditRecord;

class EditInventoryOpening extends EditRecord
{
    protected static string $resource = InventoryOpeningResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status !== InventoryOpening::STATUS_DRAFT) {
            $this->redirect(InventoryOpeningResource::getUrl('view', ['record' => $this->record]));
        }
    }
}
