<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\ReservationResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\RoomType;
use App\Models\ThirdParty;
use App\Services\Hotel\HotelAvailability;
use App\Services\Hotel\HotelReservationEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Reservas.
 *
 * Crear y modificar NO escriben directo al modelo: pasan por
 * HotelReservationEngine, que es el que consulta disponibilidad. Guardar una
 * reserva con `Model::create` desde el formulario se saltaría ese control y
 * el sobrecupo volvería por la puerta de atrás.
 */
class ReservationResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.reservations.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = Reservation::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Reservas';

    protected static ?string $modelLabel = 'Reserva';

    protected static ?string $pluralModelLabel = 'Reservas';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        $llegadas = Reservation::query()
            ->llegadasDe(now())
            ->count();

        return $llegadas > 0 ? (string) $llegadas : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Llegadas de hoy pendientes de check-in';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Estadía')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->required()
                        ->native(false)
                        ->live()
                        ->options(fn () => static::hoteles())
                        ->default(fn () => array_key_first(static::hoteles())),

                    Forms\Components\DatePicker::make('check_in_date')
                        ->label('Entrada')
                        ->required()
                        ->live()
                        ->default(now())
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcular($get, $set)),

                    Forms\Components\DatePicker::make('check_out_date')
                        ->label('Salida')
                        ->required()
                        ->live()
                        ->default(now()->addDay())
                        ->helperText('La salida no se cobra como noche: del 8 al 10 son dos noches.')
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcular($get, $set)),

                    Forms\Components\Select::make('room_type_id')
                        ->label('Tipo de habitación')
                        ->required()
                        ->native(false)
                        ->live()
                        ->options(fn (Get $get) => RoomType::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                            ->where('active', true)
                            ->orderBy('sort_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcular($get, $set)),

                    Forms\Components\Select::make('room_id')
                        ->label('Habitación')
                        ->native(false)
                        ->placeholder('Asignar después')
                        ->helperText('Se puede dejar vacía: lo que se aparta es el tipo.')
                        ->options(fn (Get $get) => static::habitacionesLibres($get)),

                    Forms\Components\Placeholder::make('disponibilidad')
                        ->label('Disponibilidad en esas fechas')
                        ->columnSpanFull()
                        ->content(fn (Get $get) => static::textoDeDisponibilidad($get)),
                ]),

            Forms\Components\Section::make('Huésped')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('guest_name')
                        ->label('A nombre de')
                        ->required()
                        ->maxLength(120),

                    Forms\Components\TextInput::make('guest_phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30),

                    Forms\Components\TextInput::make('guest_email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(150),

                    Forms\Components\TextInput::make('adults')
                        ->label('Adultos')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->default(1)
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcular($get, $set)),

                    Forms\Components\TextInput::make('children')
                        ->label('Niños')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente que paga')
                        ->native(false)
                        ->searchable()
                        ->placeholder('Se define en el check-out')
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where(fn ($q) => $q
                                ->where('name', 'ilike', "%{$search}%")
                                ->orWhere('document_number', 'ilike', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => ($t->document_number ?: '—').' · '.$t->name])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ThirdParty::find($value)?->name)
                        ->helperText('Necesario solo si se va a recibir depósito: un saldo a favor sin dueño '
                            .'no se puede cruzar con nada.'),
                ]),

            Forms\Components\Section::make('Tarifa y origen')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('rate_per_night')
                        ->label('Tarifa por noche')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->prefix('$')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcular($get, $set))
                        ->helperText('Se propone la del tipo. Lo que se pacte aquí es lo que se cobra, '
                            .'aunque la lista suba.'),

                    Forms\Components\TextInput::make('nights')
                        ->label('Noches')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('estimated_total')
                        ->label('Total estimado')
                        ->numeric()
                        ->prefix('$')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('deposit_amount')
                        ->label('Depósito pactado')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('$')
                        ->helperText('Lo pactado. La plata se recibe en la recepción, donde entra al turno de caja.'),

                    Forms\Components\Select::make('source')
                        ->label('Origen')
                        ->native(false)
                        ->options(Reservation::SOURCES)
                        ->default('direct')
                        ->required(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('check_in_date')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('guest_name')
                    ->label('Huésped')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (Reservation $record) => $record->guest_phone),

                Tables\Columns\TextColumn::make('check_in_date')
                    ->label('Entrada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Reservation $record) => $record->nights.' noche(s)'),

                Tables\Columns\TextColumn::make('check_out_date')
                    ->label('Salida')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('room.number')
                    ->label('Hab.')
                    ->fontFamily('mono')
                    ->placeholder('Sin asignar')
                    ->description(fn (Reservation $record) => $record->roomType?->name),

                Tables\Columns\TextColumn::make('estimated_total')
                    ->label('Estimado')
                    ->money('COP', 0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Reservation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Reservation::STATUS_PENDING => 'warning',
                        Reservation::STATUS_CONFIRMED => 'success',
                        Reservation::STATUS_CHECKED_IN => 'info',
                        Reservation::STATUS_COMPLETED => 'gray',
                        Reservation::STATUS_NO_SHOW, Reservation::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('source')
                    ->label('Origen')
                    ->formatStateUsing(fn (?string $state) => Reservation::SOURCES[$state] ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('stay.prefix')
                    ->label('Folio')
                    ->state(fn (Reservation $record) => $record->stay?->fullNumber())
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Reservation::STATUSES)
                    ->default(null),

                Tables\Filters\SelectFilter::make('hotel_id')->label('Hotel')->options(fn () => static::hoteles()),

                Tables\Filters\Filter::make('llegadas_hoy')
                    ->label('Llegadas de hoy')
                    ->query(fn ($query) => $query->llegadasDe(now())),

                Tables\Filters\Filter::make('vivas')
                    ->label('Solo vivas')
                    ->query(fn ($query) => $query->queBloquean()),

                Tables\Filters\Filter::make('rango')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Entra desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Entra hasta'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $v) => $q->whereDate('check_in_date', '>=', $v))
                        ->when($data['hasta'] ?? null, fn ($q, $v) => $q->whereDate('check_in_date', '<=', $v))),
            ])
            ->actions([
                Tables\Actions\Action::make('confirmar')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Reservation $record) => $record->status === Reservation::STATUS_PENDING)
                    ->action(function (Reservation $record) {
                        try {
                            app(HotelReservationEngine::class)->confirmar($record);
                            Notification::make()->title('Reserva confirmada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo confirmar')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('asignar')
                    ->label('Asignar habitación')
                    ->icon('heroicon-o-key')
                    ->color('info')
                    ->visible(fn (Reservation $record) => $record->estaViva())
                    ->form(fn (Reservation $record) => [
                        Forms\Components\Select::make('room_id')
                            ->label('Habitación')
                            ->required()
                            ->native(false)
                            ->options(fn () => app(HotelAvailability::class)
                                ->habitacionesLibres(
                                    (int) $record->hotel_id,
                                    $record->check_in_date,
                                    $record->check_out_date,
                                    $record->room_type_id,
                                    ignorarReservaId: (int) $record->id,
                                )
                                ->mapWithKeys(fn ($h) => [$h->id => $h->number.' — '.($h->roomType?->name ?? '')])
                                ->all())
                            ->helperText('Solo salen las que están libres en esas fechas.'),
                    ])
                    ->action(function (Reservation $record, array $data) {
                        try {
                            app(HotelReservationEngine::class)->asignarHabitacion($record, (int) $data['room_id']);
                            Notification::make()->title('Habitación asignada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo asignar')
                                ->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),

                Tables\Actions\Action::make('noLlego')
                    ->label('No llegó')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Libera la habitación y queda registrado como no-show, que no es lo '
                        .'mismo que una cancelación: suele implicar que el depósito no se devuelve.')
                    ->visible(fn (Reservation $record) => $record->estaViva())
                    ->action(function (Reservation $record) {
                        try {
                            app(HotelReservationEngine::class)->noLlego($record);
                            Notification::make()->title('Marcada como «no llegó»')->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo marcar')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Reservation $record) => $record->estaViva())
                    ->form([
                        Forms\Components\Textarea::make('motivo')
                            ->label('Motivo')
                            ->required()
                            ->rows(2)
                            ->helperText('Es lo que sustenta si se devuelve o no el depósito.'),
                    ])
                    ->action(function (Reservation $record, array $data) {
                        try {
                            app(HotelReservationEngine::class)->cancelar($record, (string) $data['motivo']);
                            Notification::make()->title('Reserva cancelada')->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo cancelar')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Reservation $record) => $record->estaViva()),
            ]);
    }

    /** Recalcula noches, tarifa y total estimado cuando cambia algo del rango. */
    protected static function recalcular(Get $get, Set $set): void
    {
        $entrada = $get('check_in_date');
        $salida = $get('check_out_date');

        if (! $entrada || ! $salida) {
            return;
        }

        try {
            $noches = app(HotelAvailability::class)->noches($entrada, $salida);
        } catch (\Throwable) {
            // Rango inválido mientras el usuario escribe: no vale la pena
            // gritarle en cada tecla. El motor lo rechaza al guardar.
            return;
        }

        $set('nights', $noches);

        $tarifa = (float) $get('rate_per_night');

        if ($tarifa <= 0 && $get('room_type_id')) {
            $tipo = RoomType::find($get('room_type_id'));
            $tarifa = (float) ($tipo?->tarifaPara(max(1, (int) $get('adults'))) ?? 0);
            $set('rate_per_night', $tarifa);
        }

        $set('estimated_total', round($tarifa * $noches, 2));
    }

    protected static function textoDeDisponibilidad(Get $get): string
    {
        $hotelId = (int) $get('hotel_id');
        $entrada = $get('check_in_date');
        $salida = $get('check_out_date');

        if (! $hotelId || ! $entrada || ! $salida) {
            return 'Elige hotel y fechas.';
        }

        try {
            $libres = app(HotelAvailability::class)
                ->habitacionesLibres($hotelId, $entrada, $salida, $get('room_type_id') ? (int) $get('room_type_id') : null);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if ($libres->isEmpty()) {
            return '⚠ No queda ninguna habitación libre en ese rango'
                .($get('room_type_id') ? ' para ese tipo.' : '.');
        }

        return $libres->count().' habitación(es) libre(s): '
            .$libres->take(15)->pluck('number')->implode(', ')
            .($libres->count() > 15 ? '…' : '');
    }

    /** @return array<int, string> */
    protected static function habitacionesLibres(Get $get): array
    {
        $hotelId = (int) $get('hotel_id');
        $entrada = $get('check_in_date');
        $salida = $get('check_out_date');

        if (! $hotelId || ! $entrada || ! $salida) {
            return [];
        }

        try {
            return app(HotelAvailability::class)
                ->habitacionesLibres($hotelId, $entrada, $salida, $get('room_type_id') ? (int) $get('room_type_id') : null)
                ->mapWithKeys(fn ($h) => [$h->id => $h->number.' — '.($h->roomType?->name ?? '')])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    protected static function hoteles(): array
    {
        return Hotel::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReservations::route('/'),
            'create' => Pages\CreateReservation::route('/create'),
            'edit' => Pages\EditReservation::route('/{record}/edit'),
        ];
    }
}
