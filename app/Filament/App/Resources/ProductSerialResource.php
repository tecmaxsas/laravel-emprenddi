<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ProductSerialResource\Pages;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Support\SerialsSettings;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado y búsqueda de seriales para garantías y auditoría. No tiene
 * formulario de creación — los seriales entran solo a través de compras
 * o ajustes de inventario (Engine.post los crea). Es solo lectura
 * básicamente: el usuario puede editar notas y, con permiso manage,
 * corregir el status o asignar sede.
 */
class ProductSerialResource extends Resource
{
    protected static ?string $model = ProductSerial::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Seriales';

    protected static ?string $modelLabel = 'Serial';

    protected static ?string $pluralModelLabel = 'Seriales';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        // Oculta del menú si la feature global no está activa. Si está
        // activa, se necesita el permiso serials.view.
        return SerialsSettings::enabled()
            && (bool) auth()->user()?->can('serials.view');
    }

    public static function canCreate(): bool
    {
        // No se crean manualmente — entran por compras/ajustes.
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('serials.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('serials.manage');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Serial')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->description(fn (ProductSerial $r) => $r->product?->code)
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProductSerial::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ProductSerial::STATUS_IN_STOCK => 'success',
                        ProductSerial::STATUS_SOLD => 'info',
                        ProductSerial::STATUS_RETURNED => 'warning',
                        ProductSerial::STATUS_DEFECTIVE => 'danger',
                        ProductSerial::STATUS_RESERVED => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('location.name')
                    ->label('Sede')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Entró')
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sold_at')
                    ->label('Vendido')
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('saleLine.invoice.full_number')
                    ->label('Factura venta')
                    ->state(fn (ProductSerial $r) => $r->saleLine?->invoice?->fullNumber())
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('purchaseLine.invoice.full_number')
                    ->label('Factura compra')
                    ->state(fn (ProductSerial $r) => $r->purchaseLine?->invoice?->fullNumber())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(ProductSerial::STATUSES),

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Producto')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::query()
                        ->where('tracks_serials', true)
                        ->where(function ($q) use ($search) {
                            $q->where('code', 'ilike', "%{$search}%")
                              ->orWhere('name', 'ilike', "%{$search}%");
                        })
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (Product $p) => [$p->id => "{$p->code} — {$p->name}"])
                        ->all()),

                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Sede')
                    ->options(fn () => Location::query()
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->can('serials.manage')),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product', 'location']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductSerials::route('/'),
            'view' => Pages\ViewProductSerial::route('/{record}'),
            'edit' => Pages\EditProductSerial::route('/{record}/edit'),
        ];
    }
}
