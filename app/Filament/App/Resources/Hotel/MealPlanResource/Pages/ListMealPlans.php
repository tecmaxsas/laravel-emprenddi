<?php

namespace App\Filament\App\Resources\Hotel\MealPlanResource\Pages;

use App\Filament\App\Resources\Hotel\MealPlanResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMealPlans extends ListRecords
{
    protected static string $resource = MealPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
