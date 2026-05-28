<?php

namespace App\Filament\App\Resources\GiftCardResource\RelationManagers;

use App\Models\GiftCardTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Historial inmutable de movimientos de saldo. Solo lectura.
 * No tiene CreateAction — las transacciones se generan via
 * GiftCard::charge() / refund() / cancel() o desde la accion
 * 'Ajustar saldo' del Resource padre.
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Historial de movimientos';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        GiftCardTransaction::TYPE_ISSUE => 'Emisión',
                        GiftCardTransaction::TYPE_REDEEM => 'Redención',
                        GiftCardTransaction::TYPE_REFUND => 'Devolución',
                        GiftCardTransaction::TYPE_EXPIRE => 'Expiración',
                        GiftCardTransaction::TYPE_ADJUST => 'Ajuste',
                        GiftCardTransaction::TYPE_CANCEL => 'Anulación',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        GiftCardTransaction::TYPE_ISSUE, GiftCardTransaction::TYPE_REFUND => 'success',
                        GiftCardTransaction::TYPE_REDEEM => 'info',
                        GiftCardTransaction::TYPE_ADJUST => 'warning',
                        GiftCardTransaction::TYPE_EXPIRE, GiftCardTransaction::TYPE_CANCEL => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('COP')
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Saldo después')
                    ->money('COP'),
                Tables\Columns\TextColumn::make('saleInvoice.invoice_number')
                    ->label('Factura')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario'),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->notes)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
