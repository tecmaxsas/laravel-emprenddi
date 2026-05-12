<?php

namespace App\Filament\App\Resources\ExpenseResource\Pages;

use App\Filament\App\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Resources\Pages\EditRecord;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Solo borradores se pueden editar.
        if ($this->record->status !== Expense::STATUS_DRAFT) {
            $this->redirect(ExpenseResource::getUrl('view', ['record' => $this->record]));
        }
    }
}
