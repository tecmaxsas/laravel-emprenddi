<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\DriverResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Restaurant\Driver;
use App\Support\ModuleGate;
use App\Support\RestaurantSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DriverResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.delivery.manage'; }
    protected static function managePermission(): string { return 'restaurant.delivery.manage'; }

    protected static ?string $model = Driver::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Repartidores';

    protected static ?string $modelLabel = 'Repartidor';

    protected static ?string $pluralModelLabel = 'Repartidores';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        if (! RestaurantSettings::isEnabled('delivery')) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(100)
                ->placeholder('Juan Pérez'),

            Forms\Components\TextInput::make('phone')
                ->label('Teléfono')
                ->tel()
                ->maxLength(30)
                ->placeholder('3001234567'),

            Forms\Components\TextInput::make('vehicle_type')
                ->label('Vehículo')
                ->datalist(['Moto', 'Bicicleta', 'Carro', 'A pie'])
                ->maxLength(30),

            Forms\Components\TextInput::make('license_plate')
                ->label('Placa')
                ->maxLength(20)
                ->placeholder('ABC123'),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('active')
                ->label('Activo')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Teléfono')->placeholder('—')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('vehicle_type')->label('Vehículo')->placeholder('—')->badge(),
                Tables\Columns\TextColumn::make('license_plate')->label('Placa')->fontFamily('mono')->placeholder('—'),
                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
