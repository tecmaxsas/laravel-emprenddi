<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\FixedAssetResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FixedAssetResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'accounts.view'; }
    protected static function managePermission(): string { return 'accounts.manage'; }

    protected static ?string $model = FixedAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Activos fijos';

    protected static ?string $modelLabel = 'Activo fijo';

    protected static ?string $pluralModelLabel = 'Activos fijos';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(30)
                        ->placeholder('AF-001')
                        ->unique(ignoreRecord: true)
                        ->columnSpan(2),

                    Forms\Components\Select::make('category')
                        ->label('Categoría')
                        ->options(FixedAsset::CATEGORIES)
                        ->native(false),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->placeholder('Sin asignar')
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre / descripción corta')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción detallada')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Compra y vida útil')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('purchased_at')
                        ->label('Fecha de compra')
                        ->required()
                        ->default(now()),

                    Forms\Components\DatePicker::make('activated_at')
                        ->label('Inicio de depreciación')
                        ->helperText('Desde cuándo cuenta para depreciar. Vacío = no se deprecia aún.')
                        ->default(now()),

                    Forms\Components\TextInput::make('cost')
                        ->label('Costo de adquisición')
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0)
                        ->live(onBlur: true),

                    Forms\Components\TextInput::make('residual_value')
                        ->label('Valor residual')
                        ->numeric()
                        ->prefix('$')
                        ->default(0)
                        ->minValue(0)
                        ->helperText('Valor estimado al final de la vida útil.'),

                    Forms\Components\TextInput::make('useful_life_months')
                        ->label('Vida útil (meses)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(60)
                        ->helperText('Sugerencias: vehículo 60 (5 años), equipo 36 (3 años), muebles 120 (10 años), edificio 240+ (20+).'),

                    Forms\Components\Select::make('depreciation_method')
                        ->label('Método')
                        ->options(['straight_line' => 'Línea recta'])
                        ->default('straight_line')
                        ->disabled()
                        ->dehydrated(),

                    Forms\Components\Placeholder::make('monthly_calc')
                        ->label('Depreciación mensual estimada')
                        ->content(function (Forms\Get $get) {
                            $cost = (float) ($get('cost') ?? 0);
                            $residual = (float) ($get('residual_value') ?? 0);
                            $months = (int) ($get('useful_life_months') ?? 1);
                            if ($months <= 0) return '—';
                            return '$ '.number_format(max($cost - $residual, 0) / $months, 2);
                        })
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('Cuentas contables')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('asset_account_id')
                        ->label('Cuenta del activo (DR)')
                        ->required()
                        ->searchable()
                        ->helperText('1504 Terrenos, 1516 Maquinaria, 1524 Equipo oficina, 1528 Computadores, 1540 Flota, etc.')
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '15%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName()),

                    Forms\Components\Select::make('depreciation_account_id')
                        ->label('Depreciación acumulada (CR)')
                        ->required()
                        ->searchable()
                        ->helperText('Sugerido: 1592 Depreciación acumulada (contracuenta).')
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '15%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName()),

                    Forms\Components\Select::make('depreciation_expense_account_id')
                        ->label('Gasto depreciación (DR mensual)')
                        ->required()
                        ->searchable()
                        ->helperText('Sugerido: 5160 Depreciaciones.')
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '5%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName()),
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
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')->label('Activo')->wrap()->searchable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Categoría')
                    ->formatStateUsing(fn (?string $state) => FixedAsset::CATEGORIES[$state] ?? '—')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('purchased_at')->label('Compra')->date('Y-m-d')->toggleable(),
                Tables\Columns\TextColumn::make('useful_life_months')->label('Meses VU')->alignCenter()->toggleable(),

                Tables\Columns\TextColumn::make('cost')->label('Costo')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('accumulated_depreciation')->label('Dep. acum.')->money('COP')->alignEnd()->toggleable(),

                Tables\Columns\TextColumn::make('book_value')
                    ->label('Valor en libros')
                    ->state(fn (FixedAsset $r) => $r->bookValue())
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => FixedAsset::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(FixedAsset::STATUSES),
                Tables\Filters\SelectFilter::make('category')->label('Categoría')->options(FixedAsset::CATEGORIES),
                Tables\Filters\SelectFilter::make('location_id')->label('Sede')
                    ->options(fn () => Location::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (FixedAsset $r) => $r->isActive()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFixedAssets::route('/'),
            'create' => Pages\CreateFixedAsset::route('/create'),
            'view' => Pages\ViewFixedAsset::route('/{record}'),
            'edit' => Pages\EditFixedAsset::route('/{record}/edit'),
        ];
    }
}
