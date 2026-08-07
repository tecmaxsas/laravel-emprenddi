<?php

namespace App\Filament\App\Resources\OrderTaking;

use App\Filament\App\Resources\OrderTaking\OrderResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\OrderTaking\Order;
use App\Support\ModuleGate;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'order_taking.use'; }
    protected static function managePermission(): string { return 'order_taking.use'; }

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Pedidos';
    protected static ?string $modelLabel = 'Pedido';
    protected static ?string $pluralModelLabel = 'Pedidos';
    protected static ?string $navigationGroup = 'Toma pedidos';
    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('order_taking')) return false;
        return static::permissionCanAccess();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('order_date', 'desc')->columns([
            Tables\Columns\TextColumn::make('prefix')->label('Prefijo')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('number')->label('Número')->fontFamily('mono')->sortable(),
            Tables\Columns\TextColumn::make('order_date')->label('Fecha')->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->searchable()->limit(35)->placeholder('—'),
            Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge(),
            Tables\Columns\TextColumn::make('payment_status')->label('Pago')->badge(),
        ])->actions([
            Tables\Actions\ViewAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
