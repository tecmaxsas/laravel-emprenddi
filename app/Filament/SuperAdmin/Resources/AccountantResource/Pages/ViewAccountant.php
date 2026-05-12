<?php

namespace App\Filament\SuperAdmin\Resources\AccountantResource\Pages;

use App\Filament\SuperAdmin\Resources\AccountantResource;
use App\Filament\SuperAdmin\Resources\AccountantResource\RelationManagers\ManagedCompaniesRelationManager;
use App\Models\User;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountant extends ViewRecord
{
    protected static string $resource = AccountantResource::class;

    public function getTitle(): string
    {
        return $this->record->fullName();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    /**
     * Filament 3 ViewRecord no expone los relation managers tan
     * automáticamente como EditRecord; los declaramos explícitamente
     * para que la sección "Empresas vinculadas" salga debajo del
     * infolist con su AttachAction.
     */
    public function getRelationManagers(): array
    {
        return [
            ManagedCompaniesRelationManager::class,
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Contador')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label('Nombre')->state(fn (User $u) => $u->fullName())->weight('bold'),
                    Infolists\Components\TextEntry::make('email')->label('Correo')->copyable(),
                    Infolists\Components\IconEntry::make('active')->label('Activo')->boolean(),

                    Infolists\Components\TextEntry::make('created_at')->label('Registrado')->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('last_login_at')->label('Último ingreso')->dateTime('Y-m-d H:i')->placeholder('Nunca'),
                ]),
        ]);
    }
}
