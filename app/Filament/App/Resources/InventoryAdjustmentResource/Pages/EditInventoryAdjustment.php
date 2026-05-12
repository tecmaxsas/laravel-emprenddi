<?php

namespace App\Filament\App\Resources\InventoryAdjustmentResource\Pages;

use App\Filament\App\Resources\InventoryAdjustmentResource;
use App\Models\InventoryAdjustment;
use Filament\Resources\Pages\EditRecord;

class EditInventoryAdjustment extends EditRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status !== InventoryAdjustment::STATUS_DRAFT) {
            $this->redirect(InventoryAdjustmentResource::getUrl('view', ['record' => $this->record]));
        }
    }
}
