<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CashRegisterSessionResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\CashRegisterSession;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class CashRegisterSessionResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'pos.cash_close'; }
    protected static function managePermission(): string { return 'pos.cash_close'; }

    protected static ?string $model = CashRegisterSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Sesiones de caja';

    protected static ?string $modelLabel = 'Sesión de caja';

    protected static ?string $pluralModelLabel = 'Sesiones de caja';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 60;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('opened_at')->label('Apertura')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('closed_at')->label('Cierre')->dateTime('Y-m-d H:i')->placeholder('— en curso —'),
                Tables\Columns\TextColumn::make('cashier.name')->label('Cajero')->searchable(),
                Tables\Columns\TextColumn::make('location.name')->label('Sede'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => $state === 'open' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state) => $state === 'open' ? 'Abierta' : 'Cerrada'),
                Tables\Columns\TextColumn::make('opening_amount')->label('Apertura')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('total_sales')->label('Ventas')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('invoice_count')->label('Facturas')->alignCenter(),
                Tables\Columns\TextColumn::make('closing_expected')->label('Esperado')->money('COP')->alignEnd()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('closing_counted')->label('Contado')->money('COP')->alignEnd()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('closing_difference')
                    ->label('Diferencia')
                    ->money('COP')
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(fn ($state) => $state === null
                        ? null
                        : (abs((float) $state) < 0.01 ? 'success' : ($state > 0 ? 'info' : 'danger'))),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(['open' => 'Abierta', 'closed' => 'Cerrada']),
                Tables\Filters\SelectFilter::make('location_id')->label('Sede')->relationship('location', 'name'),
                Tables\Filters\SelectFilter::make('cashier_user_id')->label('Cajero')->relationship('cashier', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Resumen')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('cashier.name')->label('Cajero'),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->color(fn (string $state) => $state === 'open' ? 'success' : 'gray')
                        ->formatStateUsing(fn (string $state) => $state === 'open' ? 'Abierta' : 'Cerrada'),
                    Infolists\Components\TextEntry::make('opened_at')->label('Apertura')->dateTime('Y-m-d H:i:s'),
                    Infolists\Components\TextEntry::make('closed_at')->label('Cierre')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                    Infolists\Components\TextEntry::make('closedBy.name')->label('Cerrada por')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Movimientos')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('opening_amount')->label('Apertura')->money('COP'),
                    Infolists\Components\TextEntry::make('total_sales')->label('Total ventas')->money('COP'),
                    Infolists\Components\TextEntry::make('invoice_count')->label('Facturas emitidas'),
                ]),

            Infolists\Components\Section::make('Cierre')
                ->visible(fn (CashRegisterSession $r) => $r->isClosed())
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('closing_expected')->label('Esperado')->money('COP'),
                    Infolists\Components\TextEntry::make('closing_counted')->label('Contado')->money('COP'),
                    Infolists\Components\TextEntry::make('closing_difference')
                        ->label('Diferencia')
                        ->money('COP')
                        ->color(fn ($state) => $state === null
                            ? null
                            : (abs((float) $state) < 0.01 ? 'success' : ($state > 0 ? 'info' : 'danger'))),
                ]),

            Infolists\Components\Section::make('Pagos por método')
                ->visible(fn (CashRegisterSession $r) => ! empty($r->payment_breakdown))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('payment_breakdown')
                        ->label('')
                        ->keyLabel('Método')
                        ->valueLabel('Monto'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (CashRegisterSession $r) => $r->opening_notes || $r->closing_notes)
                ->schema([
                    Infolists\Components\TextEntry::make('opening_notes')->label('Apertura')->placeholder('—'),
                    Infolists\Components\TextEntry::make('closing_notes')->label('Cierre')->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashRegisterSessions::route('/'),
            'view' => Pages\ViewCashRegisterSession::route('/{record}'),
        ];
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
}
