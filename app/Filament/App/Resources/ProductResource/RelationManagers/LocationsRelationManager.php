<?php

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Models\ProductLocation;
use App\Services\Inventory\InventoryEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Muestra el stock actual del producto por cada sede, junto con los
 * parámetros del pivot product_locations (min/max/reorder, override prices,
 * shelf). El stock se computa con InventoryEngine en una columna virtual.
 */
class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'productLocations';

    protected static ?string $title = 'Stock por sede';

    protected static ?string $modelLabel = 'Sede';

    protected static ?string $pluralModelLabel = 'Sedes';

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('location_id')
                ->label('Sede')
                ->relationship('location', 'name')
                ->required()
                ->disabledOn('edit'),

            Forms\Components\Toggle::make('active')
                ->label('Activo en esta sede')
                ->default(true),

            Forms\Components\TextInput::make('min_stock')->label('Stock mínimo')->numeric()->minValue(0),
            Forms\Components\TextInput::make('max_stock')->label('Stock máximo')->numeric()->minValue(0),
            Forms\Components\TextInput::make('reorder_point')->label('Punto de reorden')->numeric()->minValue(0),

            Forms\Components\TextInput::make('shelf_location')
                ->label('Ubicación física')
                ->placeholder('Pasillo 3, Estante B')
                ->maxLength(50),

            Forms\Components\TextInput::make('override_sale_price')
                ->label('Precio de venta (override)')
                ->numeric()
                ->prefix('$')
                ->helperText('Vacío = usa el precio del producto'),

            Forms\Components\TextInput::make('override_purchase_price')
                ->label('Precio de compra (override)')
                ->numeric()
                ->prefix('$')
                ->helperText('Vacío = usa el precio del producto'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Sede')
                    ->weight('semibold')
                    ->wrap(),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stock actual')
                    ->state(fn (ProductLocation $r) => app(InventoryEngine::class)
                        ->currentStock($r->product_id, $r->location_id))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('semibold')
                    ->color(function (ProductLocation $r) {
                        $stock = app(InventoryEngine::class)->currentStock($r->product_id, $r->location_id);
                        $min = (float) ($r->min_stock ?? 0);
                        if ($stock <= 0) return 'danger';
                        if ($min > 0 && $stock <= $min) return 'warning';
                        return 'success';
                    }),

                Tables\Columns\TextColumn::make('min_stock')->label('Mín.')->numeric(decimalPlaces: 2)->alignEnd()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('reorder_point')->label('Reorden')->numeric(decimalPlaces: 2)->alignEnd()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('max_stock')->label('Máx.')->numeric(decimalPlaces: 2)->alignEnd()->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('shelf_location')->label('Ubicación')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('override_sale_price')->label('Precio venta override')->money('COP')->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Asociar a sede'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->label('Quitar'),
            ]);
    }
}
