<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\TableResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Location;
use App\Models\Restaurant\ServiceZone;
use App\Models\Restaurant\Table as RTable;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TableResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.tables.manage'; }
    protected static function managePermission(): string { return 'restaurant.tables.manage'; }

    protected static ?string $model = RTable::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Mesas';

    protected static ?string $modelLabel = 'Mesa';

    protected static ?string $pluralModelLabel = 'Mesas';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('M1, T-5, VIP-3'),

                    Forms\Components\TextInput::make('label')
                        ->label('Etiqueta descriptiva')
                        ->maxLength(60)
                        ->placeholder('Mesa terraza grande')
                        ->columnSpan(2),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->live()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_main', true)
                            ->value('id'))
                        ->native(false),

                    Forms\Components\Select::make('zone_id')
                        ->label('Zona')
                        ->placeholder('Sin zona')
                        ->options(fn (Forms\Get $get) => ServiceZone::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->when($get('location_id'), fn ($q, $loc) => $q->where('location_id', $loc))
                            ->orderBy('display_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false),

                    Forms\Components\TextInput::make('capacity')
                        ->label('Capacidad (personas)')
                        ->numeric()
                        ->default(4)
                        ->minValue(1)
                        ->maxValue(50),
                ]),

            Forms\Components\Section::make('Apariencia en el mapa')
                ->description('Configuración visual. Recomendado: editar las posiciones desde la página "Mapa del salón" con drag-drop.')
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('shape')
                        ->label('Forma')
                        ->options(RTable::SHAPES)
                        ->default('square')
                        ->native(false),

                    Forms\Components\TextInput::make('pos_x')
                        ->label('Pos. X')
                        ->numeric()
                        ->default(50)
                        ->minValue(0)
                        ->maxValue(1000),

                    Forms\Components\TextInput::make('pos_y')
                        ->label('Pos. Y')
                        ->numeric()
                        ->default(50)
                        ->minValue(0)
                        ->maxValue(1000),

                    Forms\Components\TextInput::make('width')
                        ->label('Ancho (px)')
                        ->numeric()
                        ->default(80)
                        ->minValue(40)
                        ->maxValue(300),

                    Forms\Components\TextInput::make('height')
                        ->label('Alto (px)')
                        ->numeric()
                        ->default(80)
                        ->minValue(40)
                        ->maxValue(300),

                    Forms\Components\Select::make('status')
                        ->label('Estado inicial')
                        ->options(RTable::STATUSES)
                        ->default('free')
                        ->native(false),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true)
                        ->inline(false)
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')->label('Etiqueta')->placeholder('—')->wrap()->toggleable(),

                Tables\Columns\TextColumn::make('zone.name')->label('Zona')->placeholder('—'),
                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),

                Tables\Columns\TextColumn::make('capacity')->label('Cap.')->alignCenter(),

                Tables\Columns\TextColumn::make('shape')
                    ->label('Forma')
                    ->formatStateUsing(fn (string $state) => RTable::SHAPES[$state] ?? $state)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => RTable::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'free' => 'success',
                        'occupied' => 'warning',
                        'billing' => 'info',
                        'reserved' => 'primary',
                        'cleaning' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
                Tables\Filters\SelectFilter::make('zone_id')->label('Zona')
                    ->options(fn () => ServiceZone::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(RTable::STATUSES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTables::route('/'),
            'create' => Pages\CreateTable::route('/create'),
            'edit' => Pages\EditTable::route('/{record}/edit'),
            'map' => Pages\TableMap::route('/map'),
        ];
    }
}
