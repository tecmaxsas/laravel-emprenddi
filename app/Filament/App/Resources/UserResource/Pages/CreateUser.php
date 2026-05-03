<?php

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Filament\App\Resources\UserResource;
use App\Models\Company;
use App\Services\PlanLimitChecker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        $company = Company::find(Auth::user()->company_id);
        $check = app(PlanLimitChecker::class)->check($company, 'max_users');

        if (! $check['ok']) {
            Notification::make()
                ->danger()
                ->title('Límite del plan alcanzado')
                ->body("Tu plan permite máximo {$check['limit']} usuario(s).")
                ->persistent()
                ->send();
            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['is_super_admin'] = false;
        $data['email_verified_at'] = now();

        return $data;
    }
}
