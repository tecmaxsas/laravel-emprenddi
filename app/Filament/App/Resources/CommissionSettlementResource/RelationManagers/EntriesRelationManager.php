<?php

namespace App\Filament\App\Resources\CommissionSettlementResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Ventas (commission_entries) que componen una liquidacion. Solo lectura.
 */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Comisiones incluidas';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('saleInvoice.full_number')
                    ->label('Factura')
                    ->state(fn ($record) => $record->saleInvoice?->fullNumber())
                    ->url(fn ($record) => $record->saleInvoice
                        ? route('filament.app.resources.sale-invoices.view', $record->saleInvoice)
                        : null)
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('causation_date')
                    ->label('Causada')
                    ->dateTime('d/m/Y'),
                Tables\Columns\TextColumn::make('causation_basis')
                    ->label('Causación')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'collected' ? 'Al cobrar' : 'Al facturar')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('base_amount')
                    ->label('Base')
                    ->money('COP')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Comisión')
                    ->money('COP')
                    ->weight('bold')
                    ->alignEnd(),
            ])
            ->defaultSort('causation_date', 'desc');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
