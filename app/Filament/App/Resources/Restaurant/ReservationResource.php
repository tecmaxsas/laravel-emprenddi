<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\ReservationResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Location;
use App\Models\Restaurant\Reservation;
use App\Models\Restaurant\ServiceZone;
use App\Models\Restaurant\Table;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table as FTable;

class ReservationResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.reservations.manage'; }
    protected static function managePermission(): string { return 'restaurant.reservations.manage'; }

    protected static ?string $model = Reservation::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Reservaciones';

    protected static ?string $modelLabel = 'Reservación';

    protected static ?string $pluralModelLabel = 'Reservaciones';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        if (! \App\Support\RestaurantSettings::isEnabled('reservations')) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cliente')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Juan Pérez')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30)
                        ->placeholder('3001234567'),

                    Forms\Components\TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(100)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Reserva')
                ->columns(3)
                ->schema([
                    Forms\Components\DateTimePicker::make('reserved_for')
                        ->label('Fecha y hora')
                        ->required()
                        ->seconds(false)
                        ->minutesStep(15)
                        ->displayFormat('d/m/Y H:i')
                        ->default(fn () => now()->addHours(2)->setMinute(0))
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('guests')
                        ->label('Comensales')
                        ->numeric()
                        ->default(2)
                        ->minValue(1)
                        ->maxValue(50),

                    Forms\Components\TextInput::make('duration_minutes')
                        ->label('Duración (min)')
                        ->numeric()
                        ->default(90)
                        ->minValue(30)
                        ->maxValue(480)
                        ->helperText('Tiempo estimado de la mesa'),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => Location::query()->where('is_main', true)->value('id'))
                        ->live()
                        ->native(false),

                    Forms\Components\Select::make('zone_id')
                        ->label('Zona preferida (opcional)')
                        ->options(fn (Forms\Get $get) => ServiceZone::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->when($get('location_id'), fn ($q, $loc) => $q->where('location_id', $loc))
                            ->orderBy('display_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false),

                    Forms\Components\Select::make('table_id')
                        ->label('Mesa específica (opcional)')
                        ->options(fn (Forms\Get $get) => Table::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->when($get('location_id'), fn ($q, $loc) => $q->where('location_id', $loc))
                            ->when($get('zone_id'), fn ($q, $zone) => $q->where('zone_id', $zone))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Table $t) => [$t->id => "{$t->code} ({$t->capacity} pers)"])
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->helperText('Si lo dejas vacío, el host asigna al llegar.'),
                ]),

            Forms\Components\Section::make('Estado y notas')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options(Reservation::STATUSES)
                        ->default(Reservation::STATUS_PENDING)
                        ->required()
                        ->native(false),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2)
                        ->placeholder('Cumpleaños, sin gluten, ventana, etc.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(FTable $table): FTable
    {
        return $table
            ->defaultSort('reserved_for', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('reserved_for')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y · H:i')
                    ->sortable()
                    ->description(fn (Reservation $r) => $r->reserved_for->diffForHumans()),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (Reservation $r) => $r->customer_phone),

                Tables\Columns\TextColumn::make('guests')
                    ->label('Pers')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('table.code')
                    ->label('Mesa')
                    ->placeholder('— sin asignar —')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('zone.name')
                    ->label('Zona')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Reservation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Reservation::STATUS_COLORS[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Reservation::STATUSES)
                    ->default(Reservation::STATUS_PENDING),

                Tables\Filters\Filter::make('today')
                    ->label('Solo hoy')
                    ->query(fn ($query) => $query->whereDate('reserved_for', today()))
                    ->default(),

                Tables\Filters\Filter::make('upcoming')
                    ->label('Próximas 2 horas')
                    ->query(fn ($query) => $query->whereBetween('reserved_for', [
                        now()->subMinutes(30),
                        now()->addHours(2),
                    ])->whereIn('status', ['pending', 'confirmed'])),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->visible(fn (Reservation $r) => $r->status === Reservation::STATUS_PENDING)
                    ->action(function (Reservation $r) {
                        $r->update([
                            'status' => Reservation::STATUS_CONFIRMED,
                            'confirmed_at' => now(),
                        ]);
                    }),

                Tables\Actions\Action::make('no_show')
                    ->label('No vino')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $r) => in_array($r->status, ['pending', 'confirmed']))
                    ->action(function (Reservation $r) {
                        $r->update([
                            'status' => Reservation::STATUS_NO_SHOW,
                            'cancelled_at' => now(),
                        ]);
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Cancelar')
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $r) => in_array($r->status, ['pending', 'confirmed']))
                    ->action(function (Reservation $r) {
                        $r->update([
                            'status' => Reservation::STATUS_CANCELLED,
                            'cancelled_at' => now(),
                        ]);
                    }),

                Tables\Actions\EditAction::make(),
            ]);
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
