<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\ServiceZoneResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Location;
use App\Models\Restaurant\ServiceZone;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceZoneResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.tables.manage'; }
    protected static function managePermission(): string { return 'restaurant.tables.manage'; }

    protected static ?string $model = ServiceZone::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Zonas de atención';

    protected static ?string $modelLabel = 'Zona';

    protected static ?string $pluralModelLabel = 'Zonas';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(80)
                ->placeholder('Salón principal, Terraza, Barra'),

            Forms\Components\TextInput::make('code')
                ->label('Código corto')
                ->maxLength(20)
                ->placeholder('SAL, TER, BAR'),

            Forms\Components\Select::make('location_id')
                ->label('Sede')
                ->required()
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

            Forms\Components\ColorPicker::make('color')
                ->label('Color')
                ->default('#3b82f6')
                ->helperText('Se usa en el mapa para identificar la zona.'),

            Forms\Components\TextInput::make('display_order')
                ->label('Orden de aparición')
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('active')
                ->label('Activa')
                ->default(true)
                ->inline(false),

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
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),

                Tables\Columns\TextColumn::make('color')
                    ->label('Color')
                    ->formatStateUsing(fn (?string $state) => $state ?: '—')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('tables_count')
                    ->label('Mesas')
                    ->counts('tables')
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceZones::route('/'),
            'create' => Pages\CreateServiceZone::route('/create'),
            'edit' => Pages\EditServiceZone::route('/{record}/edit'),
        ];
    }
}
