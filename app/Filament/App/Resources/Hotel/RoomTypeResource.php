<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\RoomTypeResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\RoomType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Tipos de habitación. Aquí vive la tarifa.
 *
 * El campo de precio se deshabilita para quien no tenga `hotel.rates.manage`:
 * un jefe de recepción puede necesitar crear un tipo o corregir una
 * descripción sin quedar habilitado para cambiarle el precio al inventario
 * entero del hotel.
 */
class RoomTypeResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = RoomType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Tipos de habitación';

    protected static ?string $modelLabel = 'Tipo de habitación';

    protected static ?string $pluralModelLabel = 'Tipos de habitación';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 52;

    public static function form(Form $form): Form
    {
        $puedeTarifar = fn () => (bool) auth()->user()?->can('hotel.rates.manage');

        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->options(fn () => static::hoteles()),

                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(30)
                        ->placeholder('DOB'),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Doble'),

                    Forms\Components\TextInput::make('beds')
                        ->label('Camas')
                        ->maxLength(60)
                        ->placeholder('1 doble + 1 sencilla'),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Capacidad')
                ->description('Es un tope físico, no una sugerencia: cuatro personas en una sencilla '
                    .'no es una venta, es una queja.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('max_adults')
                        ->label('Adultos')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->required()
                        ->default(2),

                    Forms\Components\TextInput::make('max_children')
                        ->label('Niños')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(20)
                        ->required()
                        ->default(0),
                ]),

            Forms\Components\Section::make('Tarifa')
                ->description('Precio por noche AL PÚBLICO, con IVA incluido: lo mismo que dice el aviso '
                    .'de la recepción. La base gravable se descompone al facturar.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('base_rate')
                        ->label('Tarifa por noche')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->prefix('$')
                        ->disabled(fn () => ! $puedeTarifar())
                        ->dehydrated(fn () => $puedeTarifar())
                        ->helperText('En $0 no se puede alojar a nadie de este tipo: el check-in lo impide '
                            .'en vez de dejar un folio sin precio.'),

                    Forms\Components\TextInput::make('extra_person_rate')
                        ->label('Persona adicional')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('$')
                        ->disabled(fn () => ! $puedeTarifar())
                        ->dehydrated(fn () => $puedeTarifar())
                        ->helperText('Lo que suma cada adulto por encima del tope base. Los niños no se cobran.'),

                    Forms\Components\Placeholder::make('sin_permiso_tarifa')
                        ->label('')
                        ->columnSpanFull()
                        ->visible(fn () => ! $puedeTarifar())
                        ->content('No tienes permiso para cambiar tarifas («Editar las tarifas por tipo de '
                            .'habitación»). Puedes editar lo demás y los precios quedan como están.'),
                ]),

            Forms\Components\Section::make('Otros')
                ->columns(2)
                ->schema([
                    Forms\Components\TagsInput::make('amenities')
                        ->label('Comodidades')
                        ->placeholder('wifi, aire, tv…')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->default(0),

                    Forms\Components\Toggle::make('active')
                        ->label('Activo')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('hotel.name')->label('Hotel')->searchable()->sortable()->toggleable(),

                Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tipo')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (RoomType $record) => $record->beds),

                Tables\Columns\TextColumn::make('capacidad')
                    ->label('Capacidad')
                    ->state(fn (RoomType $record) => $record->max_adults.' ad.'
                        .($record->max_children > 0 ? ' + '.$record->max_children.' niño(s)' : ''))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('base_rate')
                    ->label('Tarifa / noche')
                    ->money('COP', 0)
                    ->sortable()
                    ->weight('semibold')
                    // Una tarifa en cero no es un dato, es una configuración
                    // pendiente: se marca para que no pase inadvertida.
                    ->color(fn (RoomType $record) => (float) $record->base_rate <= 0 ? 'danger' : null)
                    ->description(fn (RoomType $record) => (float) $record->base_rate <= 0
                        ? '⚠ Sin tarifa: no se puede alojar'
                        : null),

                Tables\Columns\TextColumn::make('extra_person_rate')
                    ->label('Persona extra')
                    ->money('COP', 0)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('rooms_count')
                    ->label('Habitaciones')
                    ->counts('rooms')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('hotel_id')->label('Hotel')->options(fn () => static::hoteles()),
                Tables\Filters\TernaryFilter::make('active')->label('Activo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
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
            'index' => Pages\ListRoomTypes::route('/'),
            'create' => Pages\CreateRoomType::route('/create'),
            'edit' => Pages\EditRoomType::route('/{record}/edit'),
        ];
    }
}
