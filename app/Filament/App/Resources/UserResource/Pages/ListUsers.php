<?php

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Filament\App\Resources\UserResource;
use App\Models\Company;
use App\Services\PlanLimitChecker;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nuevo usuario')
                ->before(function (Actions\CreateAction $action) {
                    $company = Company::find(auth()->user()->company_id);
                    $check = app(PlanLimitChecker::class)->check($company, 'max_users');

                    if (! $check['ok']) {
                        Notification::make()
                            ->danger()
                            ->title('Límite del plan alcanzado')
                            ->body("Tu plan permite máximo {$check['limit']} usuario(s). Actualmente tienes {$check['current']}.")
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
        $check = app(PlanLimitChecker::class)->check($company, 'max_users');

        return $check['limit'] === null
            ? "Usuarios: {$check['current']} (sin límite)"
            : "Usuarios: {$check['current']} / {$check['limit']}";
    }
}
