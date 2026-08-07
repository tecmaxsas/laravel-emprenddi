<?php

namespace App\Filament\App\Resources\OrderTaking\PriceListResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Precios por producto';
    protected static ?string $modelLabel = 'precio';
    protected static ?string $pluralModelLabel = 'precios';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')
                ->label('Producto')->required()->native(false)->searchable()
                ->options(fn () => Product::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('active', true)
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn ($p) => [$p->id => "{$p->code} — {$p->name}"])
                    ->all()),
            Forms\Components\TextInput::make('price_before_tax')
                ->label('Precio antes de IVA')->numeric()->minValue(0)->prefix('$')->required(),
            Forms\Components\TextInput::make('tax_amount')
                ->label('Monto IVA')->numeric()->minValue(0)->prefix('$')->default(0),
            Forms\Components\TextInput::make('price_at_public')
                ->label('Precio al público (con IVA)')->numeric()->minValue(0)->prefix('$')->required()
                ->helperText('Se muestra en el pedido y en el PDF.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('product.code')->label('SKU')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('product.name')->label('Producto')->searchable(),
                Tables\Columns\TextColumn::make('price_before_tax')->label('Base')->money('COP'),
                Tables\Columns\TextColumn::make('tax_amount')->label('IVA')->money('COP'),
                Tables\Columns\TextColumn::make('price_at_public')->label('Público')->money('COP')->weight('bold'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
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
