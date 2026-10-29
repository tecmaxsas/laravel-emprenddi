<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\StayResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Support\ClockFormat;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Estadías: el histórico.
 *
 * Es una pantalla de consulta, no de operación. Alojar, cargar y cobrar se
 * hace en la Recepción, que es donde está el contexto —el mapa, el folio, la
 * caja—. Aquí se viene a buscar «¿cuánto pagó el señor Gómez en marzo?» y a
 * reimprimir una cuenta.
 *
 * Sin formulario a propósito: una estadía editable a mano desde una tabla
 * dejaría folios que no cuadran con su factura.
 */
class StayResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.use';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = Stay::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Estadías';

    protected static ?string $modelLabel = 'Estadía';

    protected static ?string $pluralModelLabel = 'Estadías';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 25;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Ver el folio es lo único que se hace aquí, y va con el mismo permiso que
     * la recepción. Se declara porque `ChecksPermission` no cubre `canView`:
     * sin esto Filament lo resolvería por su cuenta y abriría el folio de una
     * estadía a quien no puede operar el hotel.
     */
    public static function canView($record): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Estadía')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('folio')
                        ->label('Folio')
                        ->state(fn (Stay $record) => $record->fullNumber()),

                    Infolists\Components\TextEntry::make('room.number')->label('Habitación'),

                    Infolists\Components\TextEntry::make('roomType.name')->label('Tipo'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => Stay::STATUSES[$state] ?? $state),

                    Infolists\Components\TextEntry::make('huesped')
                        ->label('Huésped')
                        ->state(fn (Stay $record) => $record->nombreDelHuesped()),

                    Infolists\Components\TextEntry::make('check_in_at')
                        ->label('Entrada')
                        ->formatStateUsing(fn ($state) => $state?->format(ClockFormat::datetime())),

                    Infolists\Components\TextEntry::make('check_out_at')
                        ->label('Salida')
                        ->formatStateUsing(fn ($state) => $state?->format(ClockFormat::datetime()))
                        ->placeholder('Sigue alojado'),

                    Infolists\Components\TextEntry::make('nights')->label('Noches'),

                    Infolists\Components\TextEntry::make('rate_per_night')->label('Tarifa / noche')->money('COP', 0),

                    Infolists\Components\TextEntry::make('personas')
                        ->label('Personas')
                        ->state(fn (Stay $record) => $record->adults.' adulto(s)'
                            .($record->children > 0 ? ' + '.$record->children.' niño(s)' : '')),

                    Infolists\Components\IconEntry::make('tax_exempt')
                        ->label('Exenta de IVA')
                        ->boolean(),

                    Infolists\Components\TextEntry::make('tax_exempt_reason')
                        ->label('Motivo de la exención')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Folio')
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('room_total')->label('Alojamiento')->money('COP', 0),
                    Infolists\Components\TextEntry::make('consumption_total')->label('Consumos')->money('COP', 0),
                    Infolists\Components\TextEntry::make('discount_total')->label('Rebajas')->money('COP', 0),
                    Infolists\Components\TextEntry::make('tax_total')->label('Impuestos')->money('COP', 0),
                    Infolists\Components\TextEntry::make('total')
                        ->label('Total')
                        ->money('COP', 0)
                        ->weight('bold')
                        ->size('lg'),
                ]),

            Infolists\Components\Section::make('Renglones')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('charges')
                        ->label('')
                        ->columns(5)
                        ->schema([
                            Infolists\Components\TextEntry::make('charge_date')->label('Fecha')->date('d/m/Y'),
                            Infolists\Components\TextEntry::make('kind')
                                ->label('Tipo')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => StayCharge::KINDS[$state] ?? $state),
                            Infolists\Components\TextEntry::make('description')->label('Concepto')->columnSpan(2),
                            Infolists\Components\TextEntry::make('total')->label('Valor')->money('COP', 0),
                        ]),
                ])
                ->collapsed(),

            Infolists\Components\Section::make('Cobro')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('invoice.prefix')
                        ->label('Factura')
                        ->state(fn (Stay $record) => $record->invoice?->fullNumber())
                        ->placeholder('Sin factura'),

                    Infolists\Components\TextEntry::make('paid_total')->label('Pagado')->money('COP', 0),

                    Infolists\Components\TextEntry::make('cancel_reason')
                        ->label('Motivo (si no se cobró)')
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('check_in_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')
                    ->label('Folio')
                    ->state(fn (Stay $record) => $record->fullNumber())
                    ->fontFamily('mono')
                    ->searchable(['number'])
                    ->sortable(['number']),

                Tables\Columns\TextColumn::make('room.number')
                    ->label('Hab.')
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('huesped')
                    ->label('Huésped')
                    ->state(fn (Stay $record) => $record->nombreDelHuesped())
                    ->weight('semibold')
                    ->description(fn (Stay $record) => $record->customer?->name),

                Tables\Columns\TextColumn::make('check_in_at')
                    ->label('Entrada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('check_out_at')
                    ->label('Salida')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sigue alojado')
                    ->sortable(),

                Tables\Columns\TextColumn::make('nights')->label('Noches')->alignCenter(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('COP', 0)
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('saldo')
                    ->label('Saldo')
                    ->state(fn (Stay $record) => $record->saldo())
                    ->money('COP', 0)
                    ->color(fn (Stay $record) => $record->saldo() > 0.01 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Stay::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Stay::STATUS_OPEN => 'info',
                        Stay::STATUS_CLOSED => 'success',
                        Stay::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('factura')
                    ->label('Factura')
                    ->state(fn (Stay $record) => $record->invoice?->fullNumber())
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(Stay::STATUSES),

                Tables\Filters\SelectFilter::make('hotel_id')
                    ->label('Hotel')
                    ->options(fn () => Hotel::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                Tables\Filters\Filter::make('rango')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Entró desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Entró hasta'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $v) => $q->whereDate('check_in_at', '>=', $v))
                        ->when($data['hasta'] ?? null, fn ($q, $v) => $q->whereDate('check_in_at', '<=', $v))),

                Tables\Filters\Filter::make('con_saldo')
                    ->label('Con saldo pendiente')
                    ->query(fn ($query) => $query->whereColumn('paid_total', '<', 'total')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver folio'),

                Tables\Actions\Action::make('imprimirFolio')
                    ->label('Imprimir cuenta')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Stay $record) => route('hotel.folio.print', $record))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStays::route('/'),
            'view' => Pages\ViewStay::route('/{record}'),
        ];
    }
}
