<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\LocationResource\Pages;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Sedes / Bodegas';

    protected static ?string $modelLabel = 'Sede';

    protected static ?string $pluralModelLabel = 'Sedes / Bodegas';

    protected static ?string $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(30)
                        ->placeholder('BOG-01, MED-PRINCIPAL, ...')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule
                                ->where('company_id', auth()->user()->company_id)
                        ),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150)
                        ->columnSpan(2),

                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(Location::TYPES)
                        ->default('mixed')
                        ->required()
                        ->native(false),

                    Forms\Components\Toggle::make('is_main')
                        ->label('Sede principal')
                        ->helperText('Solo una sede puede ser principal por empresa.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Ubicación física')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('address')->label('Dirección')->columnSpan(2),
                    Forms\Components\TextInput::make('city')->label('Ciudad'),
                    Forms\Components\TextInput::make('department')->label('Departamento'),
                    Forms\Components\TextInput::make('country')->label('País')->default('CO')->maxLength(2),
                    Forms\Components\TextInput::make('postal_code')->label('Código postal'),
                ]),

            Forms\Components\Section::make('Contacto y operación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('phone')->label('Teléfono')->tel(),
                    Forms\Components\TextInput::make('email')->label('Email')->email(),
                    Forms\Components\Select::make('manager_user_id')
                        ->label('Encargado')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => \App\Models\User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                  ->orWhere('email', 'ilike', "%{$search}%");
                            })
                            ->limit(20)
                            ->pluck('email', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => \App\Models\User::find($value)?->email),
                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD'])
                        ->default('COP')
                        ->required(),
                    Forms\Components\TextInput::make('timezone')
                        ->label('Zona horaria')
                        ->default('America/Bogota'),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('is_main', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => Location::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'store' => 'info',
                        'warehouse' => 'warning',
                        'mixed' => 'success',
                        'virtual' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_main')
                    ->label('Principal')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon(''),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('manager.email')
                    ->label('Encargado')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->date('Y-m-d')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(Location::TYPES),
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
                Tables\Filters\TernaryFilter::make('is_main')->label('Principal'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
