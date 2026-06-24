<?php

namespace App\Filament\App\Resources\Parking;

use App\Filament\App\Resources\Parking\ParkingRateResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingRate;
use App\Models\Parking\VehicleType;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ParkingRateResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'parking.manage'; }
    protected static function managePermission(): string { return 'parking.manage'; }

    protected static ?string $model = ParkingRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Tarifas';
    protected static ?string $modelLabel = 'Tarifa';
    protected static ?string $pluralModelLabel = 'Tarifas';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos generales')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')->required()->maxLength(150)
                        ->placeholder('Tarifa estándar carros')->columnSpan(2),

                    Forms\Components\Select::make('kind')
                        ->label('Tipo')->required()->native(false)
                        ->options(ParkingRate::KINDS)->default(ParkingRate::KIND_REGULAR),

                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->required()->native(false)->searchable()
                        ->options(fn () => ParkingLot::query()->where('active', true)
                            ->orderBy('name')->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('vehicle_type_id')
                        ->label('Tipo de vehículo')->native(false)->searchable()
                        ->placeholder('Cualquiera (default)')
                        ->options(fn () => VehicleType::query()->where('active', true)
                            ->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->helperText('Vacío = aplica a cualquier tipo del parqueadero.'),

                    Forms\Components\TextInput::make('priority')
                        ->label('Prioridad')->numeric()->minValue(0)->default(0)
                        ->helperText('Mayor número = gana cuando hay varias activas.'),

                    Forms\Components\DatePicker::make('valid_from')
                        ->label('Vigencia desde')->native(false)
                        ->helperText('Vacío = sin tope.'),
                    Forms\Components\DatePicker::make('valid_to')
                        ->label('Vigencia hasta')->native(false)
                        ->helperText('Vacío = sin tope.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')->default(true)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Reglas de cálculo')
                ->description('Define cómo se cobra. Los tipos compuestos (tiered) permiten distintos tramos.')
                ->columns(2)
                ->statePath('config')
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo de cálculo')->required()->native(false)->live()
                        ->options([
                            'flat' => 'Plana (monto fijo)',
                            'per_minute' => 'Por minuto',
                            'per_hour' => 'Por hora',
                            'per_day' => 'Por día (24h)',
                            'tiered' => 'Escalonada (rangos)',
                        ])->default('per_hour'),

                    Forms\Components\TextInput::make('amount')
                        ->label('Monto')->numeric()->minValue(0)->prefix('$')
                        ->visible(fn (Forms\Get $get) => in_array($get('type'), ['flat', 'per_minute', 'per_hour', 'per_day'], true))
                        ->helperText('Monto por unidad (sesión, minuto, hora o día).'),

                    Forms\Components\Select::make('rounding')
                        ->label('Redondeo')->native(false)
                        ->options([
                            'ceil' => 'Hacia arriba (ceil)',
                            'floor' => 'Hacia abajo (floor)',
                            'round' => 'Al más cercano',
                            'none' => 'Proporcional (sin redondeo)',
                        ])
                        ->default('ceil')
                        ->visible(fn (Forms\Get $get) => in_array($get('type'), ['per_minute', 'per_hour'], true)),

                    Forms\Components\TextInput::make('rounding_unit_min')
                        ->label('Redondear a múltiplos de N min')->numeric()->minValue(1)->default(1)
                        ->visible(fn (Forms\Get $get) => $get('type') === 'per_minute'),

                    Forms\Components\Repeater::make('tiers')
                        ->label('Escalones')
                        ->visible(fn (Forms\Get $get) => $get('type') === 'tiered')
                        ->reorderable(true)
                        ->defaultItems(2)
                        ->columnSpanFull()
                        ->columns(5)
                        ->schema([
                            Forms\Components\TextInput::make('from_min')->label('Desde (min)')
                                ->numeric()->minValue(0)->required(),
                            Forms\Components\TextInput::make('to_min')->label('Hasta (min)')
                                ->numeric()->minValue(1)
                                ->helperText('Vacío = ∞'),
                            Forms\Components\Select::make('type')->label('Tipo')->native(false)
                                ->options([
                                    'flat' => 'Plana',
                                    'per_minute' => 'Por minuto',
                                    'per_hour' => 'Por hora',
                                ])->default('per_hour')->required(),
                            Forms\Components\TextInput::make('amount')->label('Monto')
                                ->numeric()->minValue(0)->prefix('$')->required(),
                            Forms\Components\Select::make('rounding')->label('Redondeo')
                                ->native(false)
                                ->options([
                                    'ceil' => 'ceil',
                                    'floor' => 'floor',
                                    'round' => 'round',
                                    'none' => 'none',
                                ])
                                ->default('ceil'),
                        ]),

                    Forms\Components\TextInput::make('free_minutes')
                        ->label('Minutos de cortesía')->numeric()->minValue(0)->default(0)
                        ->helperText('Minutos iniciales sin cobro.'),

                    Forms\Components\TextInput::make('daily_cap')
                        ->label('Tope diario')->numeric()->minValue(0)->prefix('$')
                        ->helperText('Vacío = sin tope.'),

                    Forms\Components\TextInput::make('lost_ticket_amount')
                        ->label('Cobro por ticket perdido')->numeric()->minValue(0)->prefix('$')
                        ->helperText('Vacío = simula 24h con la tarifa.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('priority', 'desc')->columns([
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('parkingLot.name')->label('Parqueadero')->searchable(),
            Tables\Columns\TextColumn::make('vehicleType.name')->label('Vehículo')->placeholder('Todos'),
            Tables\Columns\TextColumn::make('kind')->label('Tipo')->badge()
                ->formatStateUsing(fn (string $s) => ParkingRate::KINDS[$s] ?? $s),
            Tables\Columns\TextColumn::make('config_type')->label('Cálculo')
                ->state(fn (ParkingRate $r) => $r->config['type'] ?? '—'),
            Tables\Columns\TextColumn::make('priority')->label('Prioridad')->alignCenter(),
            Tables\Columns\TextColumn::make('valid_from')->label('Desde')->date('Y-m-d')->toggleable(),
            Tables\Columns\TextColumn::make('valid_to')->label('Hasta')->date('Y-m-d')->toggleable(),
            Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('parking_lot_id')->label('Parqueadero')
                ->options(fn () => ParkingLot::query()->orderBy('name')->pluck('name', 'id')->all()),
            Tables\Filters\SelectFilter::make('kind')->options(ParkingRate::KINDS),
            Tables\Filters\TernaryFilter::make('active')->default(true),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingRates::route('/'),
            'create' => Pages\CreateParkingRate::route('/create'),
            'edit' => Pages\EditParkingRate::route('/{record}/edit'),
        ];
    }
}
