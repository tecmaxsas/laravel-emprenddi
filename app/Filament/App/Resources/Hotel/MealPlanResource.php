<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\MealPlanResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\MealPlan;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Planes de alimentación.
 *
 * Igual que las temporadas: ver la configuración va con `hotel.manage`, pero
 * ponerle precio exige `hotel.rates.manage`. Un plan de alimentación es
 * tarifa, y le cambia la cuenta a todos los huéspedes que lo tengan.
 */
class MealPlanResource extends Resource
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

    protected static ?string $model = MealPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-cake';

    protected static ?string $navigationLabel = 'Planes de alimentación';

    protected static ?string $modelLabel = 'Plan de alimentación';

    protected static ?string $pluralModelLabel = 'Planes de alimentación';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 55;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('El plan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->required()
                        ->native(false)
                        ->live()
                        ->options(fn () => static::hoteles()),

                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('CP')
                        ->helperText('Los del oficio: EP, CP, MAP, AP, AI. Son los que el cliente ya '
                            .'conoce de Booking y de las agencias.'),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Alojamiento + desayuno'),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->default(0),

                    Forms\Components\Textarea::make('description')
                        ->label('Qué incluye')
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Sale en la tarjeta de registro: es lo que el huésped lee para '
                            .'saber si puede bajar a desayunar.'),
                ]),

            Forms\Components\Section::make('Cómo se cobra')
                ->schema([
                    Forms\Components\Toggle::make('included_in_rate')
                        ->label('Ya va incluido en la tarifa')
                        ->live()
                        ->helperText('Encendido, no se cobra nada aparte: el plan solo queda escrito en '
                            .'el folio y en la tarjeta de registro. Es el caso de «tarifa con desayuno '
                            .'incluido».'),

                    Forms\Components\Grid::make(2)
                        ->visible(fn (Get $get) => ! $get('included_in_rate'))
                        ->schema([
                            Forms\Components\TextInput::make('price_per_adult')
                                ->label('Por adulto, por noche')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('$'),

                            Forms\Components\TextInput::make('price_per_child')
                                ->label('Por niño, por noche')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('$')
                                ->helperText('Déjalo en 0 si a los niños no se les cobra.'),
                        ]),

                    Forms\Components\Select::make('product_id')
                        ->label('Producto con el que se factura')
                        ->native(false)
                        ->searchable()
                        ->placeholder('Sin producto')
                        ->visible(fn (Get $get) => ! $get('included_in_rate'))
                        ->options(fn () => Product::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_sellable', true)
                            ->where('active', true)
                            ->orderBy('name')
                            ->limit(100)
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Recomendado: colgándolo de un producto, el cargo hereda su IVA y '
                            .'su cuenta de ingreso, y la alimentación queda separada del alojamiento en '
                            .'el estado de resultados. Sin producto, el renglón se cobra sin impuesto.'),

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
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Plan')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (MealPlan $record) => $record->description),

                Tables\Columns\TextColumn::make('cobro')
                    ->label('Cobro')
                    ->badge()
                    ->state(fn (MealPlan $record) => match (true) {
                        $record->included_in_rate => 'Incluido en la tarifa',
                        $record->seCobra() => 'Se cobra aparte',
                        default => 'Sin precio',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Incluido en la tarifa' => 'info',
                        'Se cobra aparte' => 'success',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('price_per_adult')
                    ->label('Adulto / noche')
                    ->money('COP', 0)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('price_per_child')
                    ->label('Niño / noche')
                    ->money('COP', 0)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->placeholder('Sin producto')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('hotel.name')->label('Hotel')->toggleable(),

                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('hotel_id')->label('Hotel')->options(fn () => static::hoteles()),
                Tables\Filters\TernaryFilter::make('active')->label('Activo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin planes de alimentación')
            ->emptyStateDescription('Al activar el módulo se siembran los cinco planes del oficio —EP, '
                .'CP, MAP, AP, AI— en $0. Ponles precio, o marca el que ya vaya incluido en la tarifa.');
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
            'index' => Pages\ListMealPlans::route('/'),
            'create' => Pages\CreateMealPlan::route('/create'),
            'edit' => Pages\EditMealPlan::route('/{record}/edit'),
        ];
    }
}
