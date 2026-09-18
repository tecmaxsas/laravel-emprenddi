<?php

namespace App\Filament\App\Resources\ExpenseCategoryResource\Pages;

use App\Filament\App\Resources\ExpenseCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategories extends ListRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva categoría'),
        ];
    }
}
