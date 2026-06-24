<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CategoryResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\Category;
use App\Models\Tax;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'categories.view'; }
    protected static function managePermission(): string { return 'categories.manage'; }

    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Categorías';

    protected static ?string $modelLabel = 'Categoría';

    protected static ?string $pluralModelLabel = 'Categorías';

    protected static ?string $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            Forms\Components\Section::make('Datos básicos')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->maxLength(160)
                        ->helperText('Auto-generado desde el nombre si lo dejas vacío.')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()->company_id)
                        ),

                    Forms\Components\TextInput::make('code')
                        ->label('Código (opcional)')
                        ->maxLength(30),

                    Forms\Components\Select::make('parent_id')
                        ->label('Categoría padre')
                        ->searchable()
                        ->helperText('Las cuentas contables se heredan desde la categoría padre si esta no las define.')
                        ->getSearchResultsUsing(fn (string $search) => Category::query()
                            ->where('name', 'ilike', "%{$search}%")
                            ->where('id', '!=', request()->route('record'))
                            ->orderBy('name')
                            ->limit(20)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Category::find($value)?->fullName())
                        ->placeholder('— sin padre (raíz) —'),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->default(0),

                    Forms\Components\TextInput::make('icon')
                        ->label('Icono (opcional)')
                        ->maxLength(50)
                        ->placeholder('heroicon-o-shopping-bag, heroicon-o-cake, ...')
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Cuentas contables e impuestos por defecto')
                ->description('Los productos de esta categoría heredarán estos valores automáticamente. Si un producto define el suyo propio (override), gana ese.')
                ->collapsible()
                ->collapsed(fn (?Category $record) => $record === null)
                ->columns(2)
                ->schema([
                    self::accountSelect(
                        'default_inventory_account_id',
                        'Cuenta de inventario',
                        'Típico: 1435 — Mercancías no fabricadas por la empresa.'
                    ),
                    self::accountSelect(
                        'default_sale_account_id',
                        'Cuenta de ingreso por venta',
                        'Típico: 4135 — Comercio al por mayor y al por menor.'
                    ),
                    self::accountSelect(
                        'default_cost_account_id',
                        'Cuenta de costo de venta',
                        'Típico: 6135 — Costo comercio al por mayor y al por menor.'
                    ),
                    Forms\Components\Placeholder::make('accounts_spacer')->label('')->content(''),

                    self::taxSelect(
                        'default_sale_tax_id',
                        'Impuesto de venta (IVA)',
                        ['sale', 'both'],
                        'Aplicado al vender. Típico: IVA 19%.'
                    ),
                    self::taxSelect(
                        'default_purchase_tax_id',
                        'Impuesto de compra (IVA)',
                        ['purchase', 'both'],
                        'Aplicado al comprar a proveedores.'
                    ),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Padre')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Productos')
                    ->counts('products')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Subcategorías')
                    ->counts('children')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('default_sale_account_id')
                    ->label('Cuentas')
                    ->state(fn (Category $r) => $r->default_sale_account_id !== null
                        || $r->default_cost_account_id !== null
                        || $r->default_inventory_account_id !== null)
                    ->boolean()
                    ->tooltip('¿Tiene cuentas contables por defecto definidas?'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Orden')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
                Tables\Filters\Filter::make('top_level')
                    ->label('Solo raíces')
                    ->query(fn (Builder $query) => $query->whereNull('parent_id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }

    private static function accountSelect(string $name, string $label, ?string $help = null): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label($label)
            ->helperText($help)
            ->searchable()
            ->placeholder('— Sin valor por defecto —')
            ->getSearchResultsUsing(fn (string $search) => Account::query()
                ->where('accepts_movements', true)
                ->where('active', true)
                ->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'ilike', "%{$search}%");
                })
                ->orderBy('code')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                ->all())
            ->getOptionLabelUsing(fn ($value) => Account::find($value)
                ? Account::find($value)->code.' — '.Account::find($value)->name
                : null);
    }

    private static function taxSelect(string $name, string $label, array $appliesTo, ?string $help = null): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label($label)
            ->helperText($help)
            ->searchable()
            ->placeholder('— Sin impuesto por defecto —')
            ->getSearchResultsUsing(fn (string $search) => Tax::query()
                ->where('is_active', true)
                ->whereIn('applies_to', $appliesTo)
                ->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                      ->orWhere('code', 'ilike', "%{$search}%");
                })
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (Tax $t) => [$t->id => "{$t->code} — {$t->name}"])
                ->all())
            ->getOptionLabelUsing(fn ($value) => Tax::find($value)
                ? Tax::find($value)->code.' — '.Tax::find($value)->name
                : null);
    }
}
