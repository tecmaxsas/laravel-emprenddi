<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\RoomResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\HotelZone;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RoomResource extends Resource
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

    protected static ?string $model = Room::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Habitaciones';

    protected static ?string $modelLabel = 'Habitación';

    protected static ?string $pluralModelLabel = 'Habitaciones';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 53;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('hotel_id')
                ->label('Hotel')
                ->required()
                ->native(false)
                ->searchable()
                ->live()
                ->options(fn () => static::hoteles()),

            Forms\Components\Select::make('room_type_id')
                ->label('Tipo')
                ->required()
                ->native(false)
                ->searchable()
                ->options(fn (Get $get) => RoomType::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                    ->where('active', true)
                    ->orderBy('sort_order')
                    ->pluck('name', 'id')
                    ->all())
                ->helperText('De aquí sale la tarifa de la noche.'),

            Forms\Components\Select::make('zone_id')
                ->label('Zona')
                ->native(false)
                ->searchable()
                ->placeholder('Sin zona')
                ->options(fn (Get $get) => HotelZone::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                    ->where('active', true)
                    ->orderBy('display_order')
                    ->pluck('name', 'id')
                    ->all()),

            Forms\Components\TextInput::make('number')
                ->label('Número')
                ->required()
                ->maxLength(20)
                ->placeholder('101'),

            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->maxLength(60)
                ->placeholder('Suite vista al mar'),

            Forms\Components\TextInput::make('floor')
                ->label('Piso')
                ->maxLength(30)
                ->placeholder('1'),

            Forms\Components\Select::make('status')
                ->label('Estado')
                ->required()
                ->native(false)
                ->options(Room::STATUSES)
                ->default(Room::STATUS_AVAILABLE)
                ->helperText('«Mantenimiento» y «bloqueada» sacan la habitación del inventario vendible, '
                    .'también para fechas futuras.'),

            Forms\Components\TextInput::make('max_occupancy')
                ->label('Ocupación máxima')
                ->numeric()
                ->minValue(1)
                ->maxValue(20)
                ->default(2)
                ->helperText('Tope de esta habitación en concreto. Puede ser menor que el del tipo.'),

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
            ->defaultSort('number')
            ->columns([
                Tables\Columns\TextColumn::make('hotel.name')->label('Hotel')->searchable()->toggleable(),

                Tables\Columns\TextColumn::make('zone.name')->label('Zona')->searchable()->placeholder('—'),

                Tables\Columns\TextColumn::make('number')
                    ->label('Habitación')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Room $record) => $record->name),

                Tables\Columns\TextColumn::make('roomType.name')
                    ->label('Tipo')
                    ->searchable()
                    ->description(fn (Room $record) => $record->roomType
                        ? '$'.number_format((float) $record->roomType->base_rate, 0, ',', '.').' / noche'
                        : null),

                Tables\Columns\TextColumn::make('max_occupancy')->label('Capacidad')->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Room::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Room::STATUS_AVAILABLE => 'success',
                        Room::STATUS_OCCUPIED => 'danger',
                        Room::STATUS_RESERVED => 'warning',
                        Room::STATUS_CLEANING => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('hotel_id')->label('Hotel')->options(fn () => static::hoteles()),

                Tables\Filters\SelectFilter::make('zone_id')
                    ->label('Zona')
                    ->options(fn () => HotelZone::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                Tables\Filters\SelectFilter::make('room_type_id')
                    ->label('Tipo')
                    ->options(fn () => RoomType::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(Room::STATUSES),
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
            'index' => Pages\ListRooms::route('/'),
            'create' => Pages\CreateRoom::route('/create'),
            'edit' => Pages\EditRoom::route('/{record}/edit'),
        ];
    }
}
