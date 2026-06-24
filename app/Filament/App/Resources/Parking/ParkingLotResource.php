<?php

namespace App\Filament\App\Resources\Parking;

use App\Filament\App\Resources\Parking\ParkingLotResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Parking\ParkingLot;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ParkingLotResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'parking.manage'; }
    protected static function managePermission(): string { return 'parking.manage'; }

    protected static ?string $model = ParkingLot::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Parqueaderos';
    protected static ?string $modelLabel = 'Parqueadero';
    protected static ?string $pluralModelLabel = 'Parqueaderos';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos básicos')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')->required()->maxLength(30)
                        ->placeholder('P01, NORTE, AEROP'),
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')->required()->maxLength(150),
                    Forms\Components\TextInput::make('address')
                        ->label('Dirección')->maxLength(255)->columnSpan(2),
                    Forms\Components\TextInput::make('city')->label('Ciudad')->maxLength(100),
                    Forms\Components\TextInput::make('phone')->label('Teléfono')->tel()->maxLength(30),
                    Forms\Components\TextInput::make('total_capacity')
                        ->label('Capacidad total')->numeric()->minValue(0)
                        ->helperText('Opcional. Solo se usa para alertas de aforo.'),
                    Forms\Components\Toggle::make('active')->label('Activo')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('city')->label('Ciudad')->toggleable(),
            Tables\Columns\TextColumn::make('total_capacity')->label('Cupos')->numeric()->alignCenter(),
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
            'index' => Pages\ListParkingLots::route('/'),
            'create' => Pages\CreateParkingLot::route('/create'),
            'edit' => Pages\EditParkingLot::route('/{record}/edit'),
        ];
    }
}
