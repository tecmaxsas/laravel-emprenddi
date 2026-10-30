<?php

namespace App\Filament\App\Resources\Hotel\MealPlanResource\Pages;

use App\Filament\App\Resources\Hotel\MealPlanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMealPlan extends EditRecord
{
    protected static string $resource = MealPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
