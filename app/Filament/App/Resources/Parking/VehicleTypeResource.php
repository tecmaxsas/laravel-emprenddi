<?php

namespace App\Filament\App\Resources\Parking;

use App\Filament\App\Resources\Parking\VehicleTypeResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Parking\VehicleType;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VehicleTypeResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'parking.manage'; }
    protected static function managePermission(): string { return 'parking.manage'; }

    protected static ?string $model = VehicleType::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Tipos de vehículo';
    protected static ?string $modelLabel = 'Tipo de vehículo';
    protected static ?string $pluralModelLabel = 'Tipos de vehículo';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código')->required()->maxLength(30)
                ->placeholder('CAR, MOTO, BIKE, TRUCK'),

            Forms\Components\TextInput::make('name')
                ->label('Nombre')->required()->maxLength(80),

            Forms\Components\TextInput::make('icon')
                ->label('Icono / emoji')->maxLength(50)
                ->placeholder('🚗 o heroicon-o-truck'),

            Forms\Components\TextInput::make('sort_order')
                ->label('Orden')->numeric()->default(0),

            Forms\Components\Toggle::make('active')
                ->label('Activo')->default(true)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable(),
            Tables\Columns\TextColumn::make('icon')->label('Icono'),
            Tables\Columns\TextColumn::make('rates_count')->label('Tarifas')->counts('rates')->alignCenter(),
            Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
        ])->filters([
            Tables\Filters\TernaryFilter::make('active')->default(true),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleTypes::route('/'),
            'create' => Pages\CreateVehicleType::route('/create'),
            'edit' => Pages\EditVehicleType::route('/{record}/edit'),
        ];
    }
}
