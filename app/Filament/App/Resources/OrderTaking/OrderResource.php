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
            Tables\Columns\TextColumn::make('full_number')
                ->label('Número')->state(fn (Order $r) => $r->fullNumber())
                ->fontFamily('mono')->weight('semibold')->searchable(query: fn ($q, $s) => $q
                    ->where('number', 'like', "%{$s}%")
                    ->orWhere('prefix', 'like', "%{$s}%")),
            Tables\Columns\TextColumn::make('order_date')->label('Fecha')->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->searchable()->limit(35),
            Tables\Columns\TextColumn::make('priceList.name')->label('Lista')->badge()->color('info'),
            Tables\Columns\TextColumn::make('seller.name')->label('Vendedor')->toggleable(),
            Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),
            Tables\Columns\TextColumn::make('paid_amount')->label('Pagado')->money('COP')->color('success')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('balance')->label('Saldo')->money('COP')->color('warning')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn (string $s) => Order::STATUSES[$s] ?? $s)
                ->color(fn (string $s) => match ($s) {
                    'draft' => 'gray', 'confirmed' => 'info',
                    'partial_delivered' => 'warning', 'fully_delivered' => 'success',
                    'cancelled' => 'danger', default => 'gray',
                }),
            Tables\Columns\TextColumn::make('delivery_status')->label('Entrega')->badge()
                ->formatStateUsing(fn (string $s) => Order::DELIVERY_STATUSES[$s] ?? $s)
                ->color(fn (string $s) => match ($s) {
                    'pending' => 'gray', 'partial' => 'warning', 'delivered' => 'success', default => 'gray',
                }),
            Tables\Columns\TextColumn::make('payment_status')->label('Pago')->badge()
                ->formatStateUsing(fn (string $s) => Order::PAYMENT_STATUSES[$s] ?? $s)
                ->color(fn (string $s) => match ($s) {
                    'pendiente' => 'gray', 'parcial' => 'warning', 'pagado' => 'success', default => 'gray',
                }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(Order::STATUSES),
            Tables\Filters\SelectFilter::make('delivery_status')->options(Order::DELIVERY_STATUSES)->label('Entrega'),
            Tables\Filters\SelectFilter::make('payment_status')->options(Order::PAYMENT_STATUSES)->label('Pago'),
        ])->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\Action::make('print')
                ->label('PDF')->icon('heroicon-o-printer')->color('gray')
                ->url(fn (Order $r) => route('order-taking.orders.pdf', $r->id))
                ->openUrlInNewTab(),
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
