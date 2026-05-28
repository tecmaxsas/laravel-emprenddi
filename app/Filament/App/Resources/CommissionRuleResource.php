<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CommissionRuleResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\User;
use App\Support\CommissionsSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommissionRuleResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'commissions.view'; }
    protected static function managePermission(): string { return 'commissions.manage'; }

    protected static ?string $model = CommissionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Comisiones';
    protected static ?string $modelLabel = 'Regla de comisión';
    protected static ?string $pluralModelLabel = 'Reglas de comisión';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        if (! CommissionsSettings::moduleActive()) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Regla de comisión')
                ->description('Define el % que gana un vendedor. Crea una regla "Todos los productos" como % base, y reglas por categoría o producto para sobrescribirlo en casos específicos.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('seller_user_id')
                        ->label('Vendedor')
                        ->required()
                        ->searchable()
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->id => trim($u->name.' '.$u->last_name)])
                            ->all()),

                    Forms\Components\TextInput::make('rate')
                        ->label('Porcentaje de comisión')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%'),

                    Forms\Components\Radio::make('scope')
                        ->label('Aplica a')
                        ->required()
                        ->default(CommissionRule::SCOPE_ALL)
                        ->live()
                        ->columnSpanFull()
                        ->options([
                            CommissionRule::SCOPE_ALL => 'Todos los productos (% base del vendedor)',
                            CommissionRule::SCOPE_CATEGORY => 'Una categoría específica (override)',
                            CommissionRule::SCOPE_PRODUCT => 'Un producto específico (override)',
                        ]),

                    Forms\Components\Select::make('category_id')
                        ->label('Categoría')
                        ->searchable()
                        ->preload()
                        ->required(fn (Forms\Get $get) => $get('scope') === CommissionRule::SCOPE_CATEGORY)
                        ->visible(fn (Forms\Get $get) => $get('scope') === CommissionRule::SCOPE_CATEGORY)
                        ->options(fn () => Category::query()->where('active', true)->orderBy('name')->pluck('name', 'id')),

                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->searchable()
                        ->required(fn (Forms\Get $get) => $get('scope') === CommissionRule::SCOPE_PRODUCT)
                        ->visible(fn (Forms\Get $get) => $get('scope') === CommissionRule::SCOPE_PRODUCT)
                        ->options(fn () => Product::query()->where('active', true)->orderBy('name')->limit(500)->pluck('name', 'id')),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true),

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
            ->columns([
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->formatStateUsing(fn ($record) => trim($record->seller->name.' '.$record->seller->last_name))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Aplica a')
                    ->badge()
                    ->formatStateUsing(fn (string $state, $record) => match ($state) {
                        CommissionRule::SCOPE_ALL => 'Todos',
                        CommissionRule::SCOPE_CATEGORY => 'Cat: '.($record->category?->name ?? '—'),
                        CommissionRule::SCOPE_PRODUCT => 'Prod: '.($record->product?->name ?? '—'),
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        CommissionRule::SCOPE_ALL => 'primary',
                        CommissionRule::SCOPE_CATEGORY => 'info',
                        CommissionRule::SCOPE_PRODUCT => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('rate')
                    ->label('Comisión')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' %')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('seller_user_id')
                    ->label('Vendedor')
                    ->options(fn () => User::query()
                        ->where('company_id', auth()->user()->company_id)
                        ->orderBy('name')->get()
                        ->mapWithKeys(fn ($u) => [$u->id => trim($u->name.' '.$u->last_name)])->all()),
                Tables\Filters\SelectFilter::make('scope')->label('Alcance')->options([
                    CommissionRule::SCOPE_ALL => 'Todos',
                    CommissionRule::SCOPE_CATEGORY => 'Categoría',
                    CommissionRule::SCOPE_PRODUCT => 'Producto',
                ]),
                Tables\Filters\TernaryFilter::make('active')->label('Estado'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('seller_user_id')
            ->groups([
                Tables\Grouping\Group::make('seller.name')
                    ->label('Vendedor')
                    ->getTitleFromRecordUsing(fn ($record) => trim($record->seller->name.' '.$record->seller->last_name)),
            ])
            ->emptyStateHeading('Sin reglas de comisión')
            ->emptyStateDescription('Crea una regla "Todos los productos" por vendedor para definir su % base.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionRules::route('/'),
            'create' => Pages\CreateCommissionRule::route('/create'),
            'edit' => Pages\EditCommissionRule::route('/{record}/edit'),
        ];
    }
}
