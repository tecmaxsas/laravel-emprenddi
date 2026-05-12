<?php

namespace App\Filament\App\Resources\PurchaseReturnResource\Pages;

use App\Filament\App\Resources\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseReturn extends EditRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status !== PurchaseReturn::STATUS_DRAFT) {
            $this->redirect(PurchaseReturnResource::getUrl('view', ['record' => $this->record]));
        }
    }
}
