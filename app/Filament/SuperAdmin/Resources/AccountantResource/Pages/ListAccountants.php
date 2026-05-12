<?php

namespace App\Filament\SuperAdmin\Resources\AccountantResource\Pages;

use App\Filament\SuperAdmin\Resources\AccountantResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAccountants extends ListRecords
{
    protected static string $resource = AccountantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo contador'),
        ];
    }
}
