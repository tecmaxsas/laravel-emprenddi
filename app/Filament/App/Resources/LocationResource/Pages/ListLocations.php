<?php

namespace App\Filament\App\Resources\LocationResource\Pages;

use App\Filament\App\Resources\LocationResource;
use App\Models\Company;
use App\Services\PlanLimitChecker;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nueva sede')
                ->before(function (Actions\CreateAction $action) {
                    $company = Company::find(auth()->user()->company_id);
                    $check = app(PlanLimitChecker::class)->check($company, 'max_locations');

                    if (! $check['ok']) {
                        Notification::make()
                            ->danger()
                            ->title('Límite del plan alcanzado')
                            ->body("Tu plan permite máximo {$check['limit']} sede(s). Actualmente tienes {$check['current']}. Actualiza el plan para crear más.")
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        $company = Company::find(auth()->user()->company_id);
        $check = app(PlanLimitChecker::class)->check($company, 'max_locations');

        if ($check['limit'] === null) {
            return "Sedes: {$check['current']} (sin límite)";
        }

        return "Sedes: {$check['current']} / {$check['limit']}";
    }
}
