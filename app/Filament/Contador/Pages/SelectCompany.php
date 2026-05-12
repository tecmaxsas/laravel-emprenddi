<?php

namespace App\Filament\Contador\Pages;

use App\Models\Company;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class SelectCompany extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Selecciona empresa';

    protected static ?string $navigationLabel = 'Cambiar empresa';

    protected static ?int $navigationSort = -100;  // primera en la nav

    protected static string $view = 'filament.contador.pages.select-company';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isAccountantPortal();
    }

    public function getCompanies(): \Illuminate\Database\Eloquent\Collection
    {
        return Auth::user()->activeManagedCompanies()
            ->where('companies.active', true)
            ->orderBy('legal_name')
            ->get();
    }

    public function getActiveCompanyId(): ?int
    {
        return session('accountant_active_company_id');
    }

    public function chooseCompany(int $companyId): void
    {
        $user = Auth::user();
        if (! $user->canManageCompany($companyId)) {
            Notification::make()
                ->title('Sin acceso')
                ->body('No tienes acceso a esa empresa o el vínculo fue desactivado.')
                ->danger()
                ->send();
            return;
        }

        $company = Company::find($companyId);
        session(['accountant_active_company_id' => $companyId]);

        Notification::make()
            ->title('Empresa seleccionada')
            ->body($company?->legal_name)
            ->success()
            ->send();

        $this->redirect(\App\Filament\Contador\Pages\Dashboard::getUrl());
    }
}
