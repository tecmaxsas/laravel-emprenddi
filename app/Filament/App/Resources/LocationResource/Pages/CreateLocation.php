<?php

namespace App\Filament\App\Resources\LocationResource\Pages;

use App\Filament\App\Resources\LocationResource;
use App\Models\Company;
use App\Services\PlanLimitChecker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function beforeCreate(): void
    {
        $company = Company::find(auth()->user()->company_id);
        $check = app(PlanLimitChecker::class)->check($company, 'max_locations');

        if (! $check['ok']) {
            Notification::make()
                ->danger()
                ->title('Límite del plan alcanzado')
                ->body("Tu plan permite máximo {$check['limit']} sede(s). Actualmente tienes {$check['current']}.")
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
