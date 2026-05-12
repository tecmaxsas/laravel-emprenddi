<?php

namespace App\Filament\App\Resources\InventoryTransferResource\Pages;

use App\Filament\App\Resources\InventoryTransferResource;
use App\Models\InventoryTransfer;
use Filament\Resources\Pages\EditRecord;

class EditInventoryTransfer extends EditRecord
{
    protected static string $resource = InventoryTransferResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status !== InventoryTransfer::STATUS_DRAFT) {
            $this->redirect(InventoryTransferResource::getUrl('view', ['record' => $this->record]));
        }
    }
}
