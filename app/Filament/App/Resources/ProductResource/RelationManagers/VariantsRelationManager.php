<?php

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
                    Forms\Components\KeyValue::make('attributes')
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
