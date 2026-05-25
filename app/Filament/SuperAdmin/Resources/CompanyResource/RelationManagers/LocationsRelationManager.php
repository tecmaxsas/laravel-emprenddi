<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;

use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Gestion de sedes/bodegas de una empresa desde el SuperAdmin.
 *
 * El SuperAdmin no crea ni edita sedes (eso es de la empresa) — solo
 * inspecciona cuantas tiene y puede activarlas/desactivarlas para
 * habilitar/deshabilitar el flujo de venta o inventario en cada una.
 *
 * Nota: una sede inactiva no se borra; queda como historico contable.
 */
class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    protected static ?string $title = 'Sedes y Bodegas';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'store' => 'Tienda',
                        'warehouse' => 'Bodega',
                        'mixed' => 'Mixta',
                        'virtual' => 'Virtual',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'store' => 'success',
                        'warehouse' => 'warning',
                        'mixed' => 'info',
                        'virtual' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_main')
                    ->label('Principal')
                    ->boolean()
                    ->tooltip('La sede principal recibe operaciones por defecto'),
                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Estado'),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'store' => 'Tienda',
                        'warehouse' => 'Bodega',
                        'mixed' => 'Mixta',
                        'virtual' => 'Virtual',
                    ]),
            ])
            ->actions([
                // Toggle activo/inactivo — el SuperAdmin no edita la sede
                // (eso es del admin de la empresa) pero si puede pausarla.
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn ($record) => $record->active ? 'Desactivar' : 'Activar')
                    ->icon(fn ($record) => $record->active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn ($record) => $record->active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => $record->active
                        ? "Desactivar sede {$record->name}"
                        : "Activar sede {$record->name}")
                    ->modalDescription(fn ($record) => $record->active
                        ? 'La sede no aparecerá en selectores ni operaciones nuevas. Su histórico (ventas, inventario) se mantiene intacto.'
                        : 'La sede volverá a aparecer en selectores y operaciones.')
                    ->action(function ($record) {
                        if ($record->active && $record->is_main) {
                            Notification::make()
                                ->title('No se puede desactivar')
                                ->body('Esta es la sede principal. Marca otra como principal antes de desactivarla.')
                                ->danger()
                                ->send();
                            return;
                        }
                        $record->update(['active' => ! $record->active]);
                        Notification::make()
                            ->title($record->active ? 'Sede activada' : 'Sede desactivada')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('is_main', 'desc');
    }
}
