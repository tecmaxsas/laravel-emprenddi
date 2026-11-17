<?php

namespace App\Filament\App\Resources\Billiards;

use App\Filament\App\Resources\Billiards\BilliardRateResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardRate;
use App\Models\Billiards\BilliardTableType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Las tarifas del salón.
 *
 * Lo que diferencia a un billar de un parqueadero son las franjas: la hora
 * de un martes a las tres de la tarde no vale lo que la de un viernes a las
 * diez de la noche. Una cuenta que cruza de una franja a otra se parte y
 * cada tramo paga la suya; el motor lo hace solo.
 *
 * Los montos son AL PÚBLICO (IVA incluido): es el precio del aviso de la
 * pared, que es lo que el cliente espera pagar.
 */
class BilliardRateResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'billiards.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'billiards';
    }

    protected static ?string $model = BilliardRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Tarifas';

    protected static ?string $modelLabel = 'Tarifa';

    protected static ?string $pluralModelLabel = 'Tarifas';

    protected static ?string $navigationGroup = 'Billares';

    protected static ?int $navigationSort = 33;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos generales')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')->required()->maxLength(150)
                        ->placeholder('Pool — tarifa estándar')->columnSpan(2),

                    Forms\Components\Select::make('kind')
                        ->label('Tipo')->required()->native(false)
                        ->options(BilliardRate::KINDS)->default(BilliardRate::KIND_REGULAR),

                    Forms\Components\Select::make('hall_id')
                        ->label('Sala')->required()->native(false)->searchable()
                        ->options(fn () => BilliardHall::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('table_type_id')
                        ->label('Tipo de mesa')->native(false)->searchable()
                        ->placeholder('Cualquiera')
                        ->options(fn () => BilliardTableType::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->helperText('Vacío = cualquier mesa de la sala. La que nombra el tipo gana.'),

                    Forms\Components\TextInput::make('priority')
                        ->label('Prioridad')->numeric()->minValue(0)->default(0)
                        ->helperText('Mayor número gana cuando hay varias vigentes.'),

                    Forms\Components\DatePicker::make('valid_from')
                        ->label('Vigencia desde')->native(false)
                        ->helperText('Vacío = sin tope. Útil para la tarifa de diciembre.'),

                    Forms\Components\DatePicker::make('valid_to')
                        ->label('Vigencia hasta')->native(false)
                        ->helperText('Vacío = sin tope.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')->default(true)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Cómo se cobra')
                ->description('Todos los montos son al público, con IVA incluido: el precio del aviso.')
                ->columns(2)
                ->statePath('config')
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Forma de cobro')->required()->native(false)->live()
                        ->options([
                            'per_hour' => 'Por hora',
                            'per_minute' => 'Por minuto',
                            'flat' => 'Fija por mesa (sin reloj)',
                            'per_game' => 'Por partida',
                            'tiered' => 'Escalonada (la primera hora distinta)',
                        ])
                        ->default('per_hour')
                        ->selectablePlaceholder(false),

                    Forms\Components\TextInput::make('amount')
                        ->label(fn (Forms\Get $get) => match ($get('type')) {
                            'per_minute' => 'Valor del minuto',
                            'flat' => 'Valor fijo por mesa',
                            'per_game' => 'Valor de la partida',
                            'tiered' => 'Valor de la hora fuera de los escalones',
                            default => 'Valor de la hora',
                        })
                        ->numeric()->minValue(0)->prefix('$')->required(),

                    Forms\Components\TextInput::make('free_minutes')
                        ->label('Minutos de cortesía')->numeric()->minValue(0)->default(0)
                        ->visible(fn (Forms\Get $get) => in_array($get('type'), ['per_hour', 'per_minute', 'tiered'], true))
                        ->helperText('Los primeros minutos que no se cobran.'),

                    Forms\Components\TextInput::make('minimum_minutes')
                        ->label('Mínimo de minutos')->numeric()->minValue(0)->default(0)
                        ->visible(fn (Forms\Get $get) => in_array($get('type'), ['per_hour', 'per_minute', 'tiered'], true))
                        ->helperText('60 = se cobra mínimo una hora aunque jueguen veinte minutos.'),

                    Forms\Components\Select::make('rounding')
                        ->label('Redondeo del tiempo')->native(false)
                        ->options([
                            'ceil' => 'Hacia arriba',
                            'round' => 'Al más cercano',
                            'floor' => 'Hacia abajo',
                            'none' => 'Proporcional (sin redondeo)',
                        ])
                        ->default('ceil')
                        ->visible(fn (Forms\Get $get) => in_array($get('type'), ['per_hour', 'per_minute', 'tiered'], true)),

                    Forms\Components\Select::make('rounding_unit_min')
                        ->label('Bloque de cobro')->native(false)
                        ->options([
                            1 => 'Al minuto',
                            5 => 'Bloques de 5 minutos',
                            15 => 'Bloques de 15 minutos',
                            30 => 'Media hora',
                            60 => 'Hora completa empezada',
                        ])
                        ->default(1)
                        ->visible(fn (Forms\Get $get) => in_array($get('type'), ['per_hour', 'per_minute', 'tiered'], true))
                        ->helperText('«Hora completa empezada» es lo más común: 1 h 10 min se cobran como 2 horas.'),

                    Forms\Components\TextInput::make('minimum_amount')
                        ->label('Cobro mínimo')->numeric()->minValue(0)->prefix('$')
                        ->helperText('Vacío = sin mínimo.'),

                    Forms\Components\TextInput::make('max_amount')
                        ->label('Tope por cuenta')->numeric()->minValue(0)->prefix('$')
                        ->helperText('Vacío = sin tope. Útil para la «mesa libre toda la noche».'),

                    Forms\Components\Toggle::make('per_player')
                        ->label('Cobrar por jugador')
                        ->helperText('La tarifa se multiplica por cuántos están en la mesa. Poco común, pero hay salones que lo hacen.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Franjas horarias')
                ->description('El happy hour y la tarifa de la noche. Una cuenta que cruza de una franja a otra se parte: cada tramo paga la suya.')
                ->statePath('config')
                ->schema([
                    Forms\Components\Repeater::make('schedules')
                        ->label('')
                        ->addActionLabel('Agregar franja')
                        ->reorderable()
                        ->columns(4)
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state) => trim(
                            ($state['label'] ?? 'Franja').' '
                            .($state['from'] ?? '').'–'.($state['to'] ?? '')
                        ))
                        ->helperText('Gana la primera que coincida, así que el orden importa. '
                            .'Lo que no caiga en ninguna se cobra al valor de arriba.')
                        ->schema([
                            Forms\Components\TextInput::make('label')
                                ->label('Nombre')->maxLength(60)->placeholder('Happy hour'),

                            Forms\Components\TextInput::make('from')
                                ->label('Desde')->placeholder('14:00')->required()
                                ->rule('regex:/^\d{1,2}:\d{2}$/')
                                ->validationMessages(['regex' => 'La hora va en formato HH:MM, por ejemplo 14:00.']),

                            Forms\Components\TextInput::make('to')
                                ->label('Hasta')->placeholder('18:00')->required()
                                ->rule('regex:/^\d{1,2}:\d{2}$/')
                                ->validationMessages(['regex' => 'La hora va en formato HH:MM, por ejemplo 18:00.'])
                                ->helperText('Si es menor que «Desde», la franja cruza la medianoche.'),

                            Forms\Components\TextInput::make('amount')
                                ->label('Valor')->numeric()->minValue(0)->prefix('$')->required(),

                            Forms\Components\CheckboxList::make('days')
                                ->label('Días')
                                ->columns(7)
                                ->options([
                                    1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue',
                                    5 => 'Vie', 6 => 'Sáb', 7 => 'Dom',
                                ])
                                ->columnSpanFull()
                                ->helperText('Sin marcar ninguno = todos los días.'),
                        ]),
                ])
                ->visible(fn (Forms\Get $get) => in_array($get('config.type'), ['per_hour', 'per_minute'], true))
                ->collapsed(),

            Forms\Components\Section::make('Escalones')
                ->description('«La primera hora $8.000, de ahí en adelante $6.000». Los escalones NO se parten por franja: son una regla sobre el tiempo total.')
                ->statePath('config')
                ->schema([
                    Forms\Components\Repeater::make('tiers')
                        ->label('')
                        ->addActionLabel('Agregar escalón')
                        ->columns(4)
                        ->defaultItems(0)
                        ->schema([
                            Forms\Components\TextInput::make('from_min')
                                ->label('Desde (min)')->numeric()->minValue(0)->default(0)->required(),

                            Forms\Components\TextInput::make('to_min')
                                ->label('Hasta (min)')->numeric()->minValue(0)
                                ->helperText('Vacío = hasta el final.'),

                            Forms\Components\Select::make('type')
                                ->label('Cobro')->native(false)
                                ->options([
                                    'per_hour' => 'Por hora',
                                    'per_minute' => 'Por minuto',
                                    'flat' => 'Fijo',
                                ])
                                ->default('per_hour')
                                ->selectablePlaceholder(false),

                            Forms\Components\TextInput::make('amount')
                                ->label('Valor')->numeric()->minValue(0)->prefix('$')->required(),
                        ]),
                ])
                ->visible(fn (Forms\Get $get) => $get('config.type') === 'tiered')
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            Tables\Columns\TextColumn::make('name')->label('Tarifa')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('hall.name')->label('Sala')->searchable(),
            Tables\Columns\TextColumn::make('tableType.name')->label('Tipo de mesa')->placeholder('Cualquiera'),
            Tables\Columns\TextColumn::make('config.type')->label('Cobro')->badge()
                ->formatStateUsing(fn ($state) => match ($state) {
                    'per_hour' => 'Por hora',
                    'per_minute' => 'Por minuto',
                    'flat' => 'Fija',
                    'per_game' => 'Por partida',
                    'tiered' => 'Escalonada',
                    default => (string) $state,
                }),
            Tables\Columns\TextColumn::make('config.amount')->label('Valor')->money('COP'),
            Tables\Columns\TextColumn::make('franjas')->label('Franjas')
                ->state(fn (BilliardRate $record) => count($record->config['schedules'] ?? []) ?: '—'),
            Tables\Columns\TextColumn::make('priority')->label('Prioridad')->alignRight(),
            Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('hall_id')->label('Sala')
                ->options(fn () => BilliardHall::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->orderBy('name')->pluck('name', 'id')->all()),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBilliardRates::route('/'),
            'create' => Pages\CreateBilliardRate::route('/create'),
            'edit' => Pages\EditBilliardRate::route('/{record}/edit'),
        ];
    }
}
