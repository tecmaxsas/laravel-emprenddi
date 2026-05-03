<?php

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variantes';

    protected static ?string $modelLabel = 'Variante';

    protected static ?string $pluralModelLabel = 'Variantes';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'variable';
    }

    public function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('code')
                ->label('SKU')
                ->required()
                ->maxLength(50)
                ->placeholder('POLO-S-NEGRO')
                ->unique(
                    table: 'products',
                    column: 'code',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('company_id', Auth::user()->company_id),
                ),

            Forms\Components\TextInput::make('barcode')
                ->label('Código de barras')
                ->maxLength(50),

            Forms\Components\TextInput::make('name')
                ->label('Nombre completo de la variante')
                ->required()
                ->maxLength(200)
                ->placeholder('Camiseta Polo - Talla S - Negro')
                ->columnSpan(3),

            Forms\Components\Section::make('Atributos de la variante')
                ->description('Talla, color, material, sabor, etc. — define la variación.')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\KeyValue::make('variant_attributes')
                        ->label('')
                        ->keyLabel('Atributo')
                        ->valueLabel('Valor')
                        ->keyPlaceholder('talla, color, material...')
                        ->valuePlaceholder('M, Negro, Algodón...')
                        ->reorderable(),
                ]),

            Forms\Components\Group::make()->columns(3)->schema([
                Forms\Components\TextInput::make('default_purchase_price')
                    ->label('Precio compra')
                    ->numeric()->minValue(0)->prefix('$')->default(0),

                Forms\Components\TextInput::make('default_sale_price')
                    ->label('Precio venta')
                    ->numeric()->minValue(0)->prefix('$')->default(0),

                Forms\Components\TextInput::make('min_sale_price')
                    ->label('Precio mín. venta')
                    ->numeric()->minValue(0)->prefix('$'),
            ])->columnSpanFull(),

            Forms\Components\Group::make()->columns(4)->schema([
                Forms\Components\Toggle::make('is_purchasable')->label('Se compra')->default(true),
                Forms\Components\Toggle::make('is_sellable')->label('Se vende')->default(true),
                Forms\Components\Toggle::make('track_inventory')->label('Controla inventario')->default(true),
                Forms\Components\Toggle::make('active')->label('Activa')->default(true),
            ])->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->wrap()
                    ->description(fn (Product $record) => $record->attributesLabel()),

                Tables\Columns\TextColumn::make('default_sale_price')
                    ->label('Precio venta')
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('default_purchase_price')
                    ->label('Precio compra')
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nueva variante')
                    ->mutateFormDataUsing(function (array $data) {
                        // Las variantes heredan tipo y categoría del padre
                        $parent = $this->getOwnerRecord();
                        $data['parent_product_id'] = $parent->id;
                        $data['type'] = 'good';
                        $data['category_id'] = $parent->category_id;
                        $data['unit_of_measure'] = $parent->unit_of_measure;
                        $data['default_purchase_tax_id'] = $parent->default_purchase_tax_id;
                        $data['default_sale_tax_id'] = $parent->default_sale_tax_id;
                        $data['inventory_account_id'] = $parent->inventory_account_id;
                        $data['sale_account_id'] = $parent->sale_account_id;
                        $data['cost_account_id'] = $parent->cost_account_id;

                        return $data;
                    }),

                Tables\Actions\Action::make('bulkGenerate')
                    ->label('Generar en lote')
                    ->icon('heroicon-o-squares-plus')
                    ->color('info')
                    ->modalHeading('Generar variantes en lote')
                    ->modalDescription('Define los atributos y sus valores. Se crea automáticamente una variante por cada combinación posible.')
                    ->modalSubmitActionLabel('Generar')
                    ->modalWidth('3xl')
                    ->form([
                        Forms\Components\TextInput::make('sku_prefix')
                            ->label('Prefijo de SKU')
                            ->default(fn () => $this->getOwnerRecord()->code)
                            ->required()
                            ->helperText('Cada variante se creará como PREFIJO-VALOR1-VALOR2.'),

                        Forms\Components\Repeater::make('attribute_groups')
                            ->label('Atributos a combinar')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('+ Añadir atributo')
                            ->reorderableWithButtons()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre del atributo')
                                    ->placeholder('talla, color, material...')
                                    ->required()
                                    ->columnSpan(1),

                                Forms\Components\TagsInput::make('values')
                                    ->label('Valores')
                                    ->placeholder('S, M, L (Enter para añadir cada uno)')
                                    ->required()
                                    ->columnSpan(2),
                            ])
                            ->columns(3)
                            ->itemLabel(fn (array $state) => isset($state['name'])
                                ? $state['name'].' ('.count($state['values'] ?? []).' valores)'
                                : 'Atributo'),

                        Forms\Components\Section::make('Defaults heredados (opcional)')
                            ->columns(2)
                            ->collapsed()
                            ->schema([
                                Forms\Components\TextInput::make('default_sale_price')
                                    ->label('Precio venta')
                                    ->numeric()->minValue(0)->prefix('$')->default(0)
                                    ->helperText('Aplicado a todas las variantes generadas (luego puedes ajustar individualmente).'),

                                Forms\Components\TextInput::make('default_purchase_price')
                                    ->label('Precio compra')
                                    ->numeric()->minValue(0)->prefix('$')->default(0),
                            ]),
                    ])
                    ->action(function (array $data) {
                        $parent = $this->getOwnerRecord();
                        $companyId = Auth::user()->company_id;
                        $prefix = strtoupper(trim($data['sku_prefix']));
                        $groups = $data['attribute_groups'] ?? [];

                        // Cartesian product de todos los atributos
                        $combinations = [[]];
                        foreach ($groups as $group) {
                            $name = trim($group['name'] ?? '');
                            $values = $group['values'] ?? [];
                            if ($name === '' || empty($values)) {
                                continue;
                            }
                            $next = [];
                            foreach ($combinations as $existing) {
                                foreach ($values as $value) {
                                    $next[] = $existing + [$name => $value];
                                }
                            }
                            $combinations = $next;
                        }

                        if (count($combinations) <= 1 && empty($combinations[0] ?? null)) {
                            Notification::make()->danger()
                                ->title('No hay combinaciones para generar')
                                ->send();

                            return;
                        }

                        $created = 0;
                        $skipped = 0;
                        $salePrice = (float) ($data['default_sale_price'] ?? 0);
                        $purchasePrice = (float) ($data['default_purchase_price'] ?? 0);

                        foreach ($combinations as $combo) {
                            $skuSuffix = collect($combo)
                                ->map(fn ($v) => Str::upper(Str::slug((string) $v)))
                                ->join('-');
                            $sku = $skuSuffix ? "{$prefix}-{$skuSuffix}" : $prefix;

                            $exists = Product::withoutGlobalScopes()
                                ->where('company_id', $companyId)
                                ->where('code', $sku)
                                ->exists();

                            if ($exists) {
                                $skipped++;

                                continue;
                            }

                            $nameSuffix = collect($combo)
                                ->map(fn ($v, $k) => ucfirst($k).' '.$v)
                                ->join(' / ');
                            $variantName = $nameSuffix ? "{$parent->name} — {$nameSuffix}" : $parent->name;

                            Product::create([
                                'company_id' => $companyId,
                                'parent_product_id' => $parent->id,
                                'variant_attributes' => $combo,
                                'code' => $sku,
                                'name' => $variantName,
                                'type' => 'good',
                                'category_id' => $parent->category_id,
                                'unit_of_measure' => $parent->unit_of_measure,
                                'default_sale_price' => $salePrice,
                                'default_purchase_price' => $purchasePrice,
                                'default_purchase_tax_id' => $parent->default_purchase_tax_id,
                                'default_sale_tax_id' => $parent->default_sale_tax_id,
                                'inventory_account_id' => $parent->inventory_account_id,
                                'sale_account_id' => $parent->sale_account_id,
                                'cost_account_id' => $parent->cost_account_id,
                                'is_purchasable' => true,
                                'is_sellable' => true,
                                'track_inventory' => true,
                                'active' => true,
                            ]);
                            $created++;
                        }

                        Notification::make()
                            ->success()
                            ->title('Variantes generadas')
                            ->body("Creadas: {$created} · Saltadas (SKU ya existía): {$skipped}")
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
