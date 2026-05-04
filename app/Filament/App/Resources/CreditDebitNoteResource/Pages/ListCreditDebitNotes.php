<?php

namespace App\Filament\App\Resources\CreditDebitNoteResource\Pages;

use App\Filament\App\Resources\CreditDebitNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCreditDebitNotes extends ListRecords
{
    protected static string $resource = CreditDebitNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva NC / ND'),
        ];
    }
}
