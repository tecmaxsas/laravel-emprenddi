<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\Pages;

use App\Filament\App\Resources\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditPayrollPeriod extends EditRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (PayrollPeriod $record) => $record->isDraft()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->record->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Solo se puede editar un período en borrador. Vuelve a liquidar si necesitas recalcular.',
            ]);
        }

        return $data;
    }
}
