<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\HotelZoneResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\HotelZone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HotelZoneResource extends Resource
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

    protected static ?string $model = HotelZone::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Zonas';

    protected static ?string $modelLabel = 'Zona';

    protected static ?string $pluralModelLabel = 'Zonas';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 51;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('hotel_id')
                ->label('Hotel')
                ->required()
                ->native(false)
                ->searchable()
                ->options(fn () => static::hoteles()),

            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(80)
                ->placeholder('Piso 2, Torre Sur, Bungalows'),

            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->maxLength(20)
                ->placeholder('P2'),

            Forms\Components\ColorPicker::make('color')
                ->label('Color en el mapa')
                ->default('#3b82f6')
                ->helperText('Para distinguir las zonas de un vistazo en la recepción.'),

            Forms\Components\TextInput::make('display_order')
                ->label('Orden')
                ->numeric()
                ->default(0)
                ->helperText('Define en qué orden salen las zonas en el mapa. Menor primero.'),

            Forms\Components\Toggle::make('active')
                ->label('Activa')
                ->default(true),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->columns([
                Tables\Columns\TextColumn::make('hotel.name')->label('Hotel')->searchable()->sortable(),

                Tables\Columns\ColorColumn::make('color')->label(''),

                Tables\Columns\TextColumn::make('name')
                    ->label('Zona')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->toggleable(),

                Tables\Columns\TextColumn::make('rooms_count')
                    ->label('Habitaciones')
                    ->counts('rooms')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('display_order')->label('Orden')->alignCenter()->toggleable(),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('hotel_id')
                    ->label('Hotel')
                    ->options(fn () => static::hoteles()),
                Tables\Filters\TernaryFilter::make('active')->label('Activa'),
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
            'index' => Pages\ListHotelZones::route('/'),
            'create' => Pages\CreateHotelZone::route('/create'),
            'edit' => Pages\EditHotelZone::route('/{record}/edit'),
        ];
    }
}
