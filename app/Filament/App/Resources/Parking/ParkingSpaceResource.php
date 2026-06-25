<?php

namespace App\Filament\App\Resources\Parking;

use App\Filament\App\Resources\Parking\ParkingSpaceResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSpace;
use App\Models\Parking\VehicleType;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ParkingSpaceResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'parking.manage'; }
    protected static function managePermission(): string { return 'parking.manage'; }

    protected static ?string $model = ParkingSpace::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Espacios';
    protected static ?string $modelLabel = 'Espacio';
    protected static ?string $pluralModelLabel = 'Espacios';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 35;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('parking_lot_id')
                ->label('Parqueadero')->required()->native(false)->searchable()
                ->options(fn () => ParkingLot::query()->where('active', true)
                    ->orderBy('name')->pluck('name', 'id')->all()),

            Forms\Components\Select::make('vehicle_type_id')
                ->label('Tipo de vehículo')->native(false)->searchable()
                ->placeholder('Cualquier tipo')
                ->options(fn () => VehicleType::query()->where('active', true)
                    ->orderBy('sort_order')->pluck('name', 'id')->all())
                ->helperText('Vacío = cualquier vehículo. Útil para zonas exclusivas (motos, camiones).'),

            Forms\Components\TextInput::make('code')->label('Código')->required()
                ->maxLength(30)->placeholder('A-01, B-12'),

            Forms\Components\TextInput::make('zone')->label('Zona')
                ->maxLength(50)->placeholder('Piso 1, Sótano, Norte'),

            Forms\Components\Select::make('status')->label('Estado')->required()->native(false)
                ->options(ParkingSpace::STATUSES)->default(ParkingSpace::STATUS_FREE),

            Forms\Components\Toggle::make('is_accessibility')
                ->label('Accesible (discapacidad)')
                ->helperText('Marca espacios prioritarios según la ley colombiana.'),

            Forms\Components\Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            Tables\Columns\TextColumn::make('parkingLot.name')->label('Parqueadero')->searchable(),
            Tables\Columns\TextColumn::make('zone')->label('Zona')->searchable()->toggleable(),
            Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('vehicleType.name')->label('Tipo')->placeholder('Cualquiera'),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn (string $state) => ParkingSpace::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    ParkingSpace::STATUS_FREE => 'success',
                    ParkingSpace::STATUS_OCCUPIED => 'danger',
                    ParkingSpace::STATUS_RESERVED => 'warning',
                    ParkingSpace::STATUS_MAINTENANCE => 'gray',
                    default => 'gray',
                }),
            Tables\Columns\IconColumn::make('is_accessibility')
                ->label('Accesible')->boolean()->toggleable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('parking_lot_id')->label('Parqueadero')
                ->options(fn () => ParkingLot::query()->orderBy('name')->pluck('name', 'id')->all()),
            Tables\Filters\SelectFilter::make('status')->options(ParkingSpace::STATUSES),
            Tables\Filters\TernaryFilter::make('is_accessibility')->label('Accesible'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingSpaces::route('/'),
            'create' => Pages\CreateParkingSpace::route('/create'),
            'edit' => Pages\EditParkingSpace::route('/{record}/edit'),
        ];
    }
}
