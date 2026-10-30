<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\RateSeasonResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\RateSeason;
use App\Models\Hotel\RoomType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Temporadas: diciembre, Semana Santa, los puentes.
 *
 * Ver la configuración va con `hotel.manage`, pero **cambiar una tarifa exige
 * `hotel.rates.manage`**: es el precio de todo el inventario del hotel durante
 * un mes, y no es lo mismo que poder crear una zona.
 */
class RateSeasonResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.manage';
    }

    protected static function managePermission(): string
    {
        return 'hotel.rates.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = RateSeason::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static ?string $navigationLabel = 'Temporadas';

    protected static ?string $modelLabel = 'Temporada';

    protected static ?string $pluralModelLabel = 'Temporadas';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 54;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Qué y cuándo')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->required()
                        ->native(false)
                        ->live()
                        ->options(fn () => static::hoteles()),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Temporada alta diciembre'),

                    Forms\Components\DatePicker::make('start_date')
                        ->label('Desde')
                        ->required(),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('Hasta')
                        ->required()
                        ->helperText('Las dos fechas se incluyen: «del 15 de diciembre al 15 de enero» '
                            .'cubre los dos días.')
                        ->afterOrEqual('start_date'),

                    Forms\Components\Select::make('room_type_id')
                        ->label('Tipo de habitación')
                        ->native(false)
                        ->placeholder('Todos los tipos')
                        ->options(fn (Get $get) => RoomType::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                            ->where('active', true)
                            ->orderBy('sort_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Vacío = aplica a todos. Es el caso normal: en diciembre sube todo, '
                            .'no un tipo.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Precio')
                ->description('Precio al público por noche, con IVA incluido — igual que la tarifa del '
                    .'tipo de habitación. Cada noche de la estadía se cobra a lo que valga su fecha.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('rate')
                        ->label('Tarifa por noche')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->prefix('$'),

                    Forms\Components\TextInput::make('extra_person_rate')
                        ->label('Persona adicional')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->helperText('Vacío = se usa el del tipo de habitación.'),
                ]),

            Forms\Components\Section::make('Cuando dos temporadas se pisan')
                ->schema([
                    Forms\Components\TextInput::make('priority')
                        ->label('Prioridad')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->default(0)
                        ->helperText('Gana la mayor. Sirve para meter un puente caro de tres días dentro '
                            .'de una temporada alta de un mes: la temporada en 10, el puente en 50.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Temporada')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (RateSeason $record) => $record->roomType?->name ?? 'Todos los tipos'),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (RateSeason $record) => $record->dias().' día(s)'),

                Tables\Columns\TextColumn::make('rate')
                    ->label('Tarifa / noche')
                    ->money('COP', 0)
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridad')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('vigencia')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (RateSeason $record) => match (true) {
                        ! $record->active => 'Inactiva',
                        $record->end_date->copy()->endOfDay()->lt(now()) => 'Ya pasó',
                        $record->start_date->copy()->startOfDay()->gt(now()) => 'Próxima',
                        default => 'En curso',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'En curso' => 'success',
                        'Próxima' => 'info',
                        'Ya pasó' => 'gray',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('hotel.name')->label('Hotel')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('hotel_id')->label('Hotel')->options(fn () => static::hoteles()),

                Tables\Filters\Filter::make('vigentes')
                    ->label('Solo vigentes')
                    ->query(fn ($query) => $query
                        ->where('active', true)
                        ->whereDate('end_date', '>=', now()->toDateString())),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin temporadas')
            ->emptyStateDescription('Sin temporadas, cada noche se cobra a la tarifa del tipo de '
                .'habitación. Crea una para diciembre, Semana Santa o los puentes y el sistema la '
                .'aplica solo en esas fechas — sin tener que acordarse de devolver el precio después.');
    }

    /** @return array<int, string> */
    protected static function hoteles(): array
    {
        return Hotel::query()
            ->where('company_id', auth()->user()?->company_id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRateSeasons::route('/'),
            'create' => Pages\CreateRateSeason::route('/create'),
            'edit' => Pages\EditRateSeason::route('/{record}/edit'),
        ];
    }
}
