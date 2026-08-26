<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CommissionSettlementResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\CommissionEntry;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Support\CommissionsSettings;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CommissionSettlementResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'commissions.view'; }
    protected static function managePermission(): string { return 'commissions.settle'; }

    protected static ?string $model = CommissionSettlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Liquidación Comisiones';
    protected static ?string $modelLabel = 'Liquidación de comisión';
    protected static ?string $pluralModelLabel = 'Liquidaciones de comisión';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 120;

    public static function canAccess(): bool
    {
        if (! CommissionsSettings::moduleActive()) return false;
        return static::permissionCanAccess();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->formatStateUsing(fn ($record) => trim($record->seller->name.' '.$record->seller->last_name))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_start')
                    ->label('Período')
                    ->formatStateUsing(fn ($record) => $record->period_start->format('d/m/Y').' — '.$record->period_end->format('d/m/Y')),
                Tables\Columns\TextColumn::make('entries_count')
                    ->label('Ventas')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total comisión')
                    ->money('COP')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        CommissionSettlement::STATUS_DRAFT => 'Borrador',
                        CommissionSettlement::STATUS_PAID => 'Liquidada',
                        default => $state,
                    })
                    ->color(fn (string $state) => $state === CommissionSettlement::STATUS_PAID ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('journalEntry.full_number')
                    ->label('Asiento')
                    ->state(fn ($record) => $record->journalEntry?->fullNumber())
                    ->placeholder('—')
                    ->url(fn ($record) => $record->journalEntry
                        ? route('filament.app.resources.journal-entries.view', $record->journalEntry)
                        : null),
                Tables\Columns\TextColumn::make('settled_at')
                    ->label('Liquidada el')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('seller_user_id')
                    ->label('Vendedor')
                    ->options(fn () => User::query()
                        ->where('company_id', auth()->user()->company_id)
                        ->orderBy('name')->get()
                        ->mapWithKeys(fn ($u) => [$u->id => trim($u->name.' '.$u->last_name)])->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin liquidaciones')
            ->emptyStateDescription('Usa "Liquidar comisiones" para generar la primera liquidación de un vendedor.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    public static function getRelations(): array
    {
        return [
            CommissionSettlementResource\RelationManagers\EntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionSettlements::route('/'),
            'view' => Pages\ViewCommissionSettlement::route('/{record}'),
        ];
    }
}
