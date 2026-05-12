<?php

namespace App\Filament\App\Resources\FixedAssetResource\Pages;

use App\Filament\App\Resources\FixedAssetResource;
use App\Services\Assets\FixedAssetEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo activo'),

            Actions\Action::make('runMonthlyDepreciation')
                ->label('Correr depreciación del mes')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Forms\Components\TextInput::make('year')
                        ->label('Año')
                        ->numeric()
                        ->required()
                        ->default(now()->year),
                    Forms\Components\Select::make('month')
                        ->label('Mes')
                        ->required()
                        ->options(\App\Models\FiscalPeriod::MONTHS_LABELS)
                        ->default(now()->month),
                ])
                ->modalHeading('Generar depreciación mensual')
                ->modalDescription('Crea un asiento de depreciación por cada activo elegible. Idempotente — si ya se corrió ese mes para un activo, se omite.')
                ->action(function (array $data) {
                    try {
                        $count = app(FixedAssetEngine::class)->depreciatePeriod(
                            Auth::user()->company_id,
                            (int) $data['year'],
                            (int) $data['month'],
                        );
                        Notification::make()
                            ->title($count > 0 ? "Depreciación generada: {$count} activo(s)" : 'Nada que depreciar')
                            ->body($count > 0 ? 'Asientos creados correctamente.' : 'Todos los activos ya estaban depreciados en ese período o no son elegibles.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
