<?php

namespace App\Filament\App\Resources\OrderTaking;

use App\Filament\App\Resources\OrderTaking\OrderResource\Pages;
use App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers;
use App\Filament\Concerns\ChecksPermission;
use App\Models\OrderTaking\Order;
use App\Support\ModuleGate;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        return $table
            ->defaultSort('order_date', 'desc')
            // withTrashed: si el cliente se dio de baja despues, el pedido no
            // puede quedarse sin saber a nombre de quien fue.
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'customer' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('prefix')->label('Prefijo')->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('number')
                    ->label('Número')
                    // El numero completo, como en el PDF y el informe: "2" a
                    // secas no es el nombre del documento en ningun otro lado.
                    ->state(fn (Order $record) => $record->fullNumber())
                    ->fontFamily('mono')->sortable()
                    // La busqueda por defecto miraria la columna number, y
                    // "PED-000002" nunca igualaria a 2. Se acepta el numero
                    // escrito como sea: completo, con ceros o pelado.
                    ->searchable(query: function (Builder $query, string $search) {
                        $digitos = ltrim(preg_replace('/\D/', '', $search), '0');

                        return $query->where(function (Builder $q) use ($search, $digitos) {
                            $q->where('prefix', 'ilike', "%{$search}%");

                            if ($digitos !== '') {
                                $q->orWhere('number', (int) $digitos);
                            }
                        });
                    }),

                Tables\Columns\TextColumn::make('order_date')->label('Fecha')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->searchable()->limit(35)->placeholder('—'),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state) => match ($state) {
                        Order::STATUS_DRAFT => 'gray',
                        Order::STATUS_CONFIRMED => 'info',
                        Order::STATUS_PARTIAL_DELIVERED => 'warning',
                        Order::STATUS_FULLY_DELIVERED => 'success',
                        Order::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Pago')->badge()
                    ->formatStateUsing(fn (?string $state) => Order::PAYMENT_STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state) => match ($state) {
                        'pendiente' => 'gray',
                        'parcial' => 'warning',
                        'pagado' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('saleInvoice.number')
                    ->label('Factura')
                    ->state(fn (Order $record) => $record->saleInvoice?->fullNumber())
                    ->fontFamily('mono')->badge()->color('success')->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')->options(Order::STATUSES),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Pago')->options(Order::PAYMENT_STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DeliveriesRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
