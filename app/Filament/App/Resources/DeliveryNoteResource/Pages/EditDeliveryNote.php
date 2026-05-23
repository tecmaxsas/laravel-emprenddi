<?php

namespace App\Filament\App\Resources\DeliveryNoteResource\Pages;

use App\Filament\App\Resources\DeliveryNoteResource;
use App\Models\DeliveryNote;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDeliveryNote extends EditRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn (DeliveryNote $record) => $record->isDraft()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->record->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden editar remisiones en borrador.',
            ]);
        }

        $lineNum = 1;
        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum) {
            $line['line_number'] = $lineNum++;
            return $line;
        })->all();

        return $data;
    }
}
