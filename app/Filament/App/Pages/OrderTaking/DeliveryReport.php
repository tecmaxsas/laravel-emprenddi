<?php

namespace App\Filament\App\Pages\OrderTaking;

use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\ThirdParty;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Que se despacho y que falta por despachar, pedido por pedido.
 *
 * Las cantidades salen de las lineas (quantity_ordered vs quantity_delivered)
 * con subconsultas, no cargando los items en memoria: el informe puede abarcar
 * meses de pedidos.
 *
 * Cada fila se puede desplegar para ver el detalle linea por linea, que es
 * donde de verdad se ve que producto quedo pendiente.
 */
class DeliveryReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Despachos y pendientes';

    protected static ?string $navigationGroup = 'Toma pedidos';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'order-taking/delivery-report';

    protected static ?string $title = 'Pedidos: despachado vs. pendiente';

    protected static string $view = 'filament.app.pages.reports.report-page';

    public ?array $filters = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('order_taking')) {
            return false;
        }

        return (bool) auth()->user()?->can('order_taking.use');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'third_party_id' => null,
            'delivery_status' => null,
            'only_pending' => false,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(5)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->native(false)->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->native(false)->required()->live(),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')->placeholder('Todos')->searchable()->live()
                        ->options(fn () => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->orderBy('name')
                            ->limit(200)
                            ->pluck('name', 'id')
                            ->all()),

                    Forms\Components\Select::make('delivery_status')
                        ->label('Estado de despacho')->placeholder('Todos')->native(false)->live()
                        ->options(Order::DELIVERY_STATUSES),

                    Forms\Components\Toggle::make('only_pending')
                        ->label('Solo con pendientes')
                        ->helperText('Oculta los pedidos ya despachados por completo.')
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->baseQuery())
            ->defaultSort('order_date', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Pedido')
                    ->state(fn (Order $record) => $record->fullNumber())
                    ->fontFamily('mono')->weight('semibold')->sortable(),

                Tables\Columns\TextColumn::make('order_date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')->limit(30)->placeholder('—')->searchable(),

                Tables\Columns\TextColumn::make('qty_ordered')
                    ->label('Pedido')
                    ->numeric(decimalPlaces: 2)->alignEnd()->sortable(),

                Tables\Columns\TextColumn::make('qty_delivered')
                    ->label('Despachado')
                    ->numeric(decimalPlaces: 2)->alignEnd()->color('success')->sortable(),

                Tables\Columns\TextColumn::make('qty_pending')
                    ->label('Pendiente')
                    ->state(fn (Order $record) => max(0, round((float) $record->qty_ordered - (float) $record->qty_delivered, 2)))
                    ->numeric(decimalPlaces: 2)->alignEnd()->weight('bold')
                    ->color(fn (Order $record) => (float) $record->qty_ordered - (float) $record->qty_delivered > 0.0001
                        ? 'warning'
                        : 'success'),

                Tables\Columns\TextColumn::make('avance')
                    ->label('Avance')
                    ->state(function (Order $record) {
                        $ordered = (float) $record->qty_ordered;

                        return $ordered > 0
                            ? round(((float) $record->qty_delivered / $ordered) * 100, 1).' %'
                            : '—';
                    })
                    ->badge()
                    ->color(function (Order $record) {
                        $ordered = (float) $record->qty_ordered;
                        if ($ordered <= 0) {
                            return 'gray';
                        }
                        $pct = ((float) $record->qty_delivered / $ordered) * 100;

                        return match (true) {
                            $pct >= 99.99 => 'success',
                            $pct > 0 => 'warning',
                            default => 'gray',
                        };
                    })
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('delivery_status')
                    ->label('Despacho')->badge()
                    ->formatStateUsing(fn (?string $state) => Order::DELIVERY_STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state) => match ($state) {
                        'delivered' => 'success',
                        'partial' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('balance')->label('Saldo')->money('COP')->alignEnd()
                    ->color('warning')->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? (string) $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(null)
            // El detalle por linea es el que responde "que producto falta".
            ->actions([
                Tables\Actions\Action::make('detalle')
                    ->label('Detalle')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (Order $record) => 'Líneas del pedido '.$record->fullNumber())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Order $record) => view(
                        'filament.app.pages.order-taking.delivery-report-detail',
                        ['lineas' => $this->lineasDe($record->id)],
                    )),
            ])
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->summaryLabel())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->disabled(),
            ]);
    }

    /**
     * Cantidades agregadas por subconsulta. Cargar items en memoria para
     * sumarlos haria inviable el informe en cuanto haya volumen.
     */
    protected function baseQuery(): Builder
    {
        $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

        $sum = fn (string $column) => OrderItem::query()
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('order_taking_order_items.order_id', 'order_taking_orders.id');

        $query = Order::query()
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $to)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->when($this->filters['third_party_id'] ?? null,
                fn (Builder $q, $v) => $q->where('third_party_id', $v))
            ->when($this->filters['delivery_status'] ?? null,
                fn (Builder $q, $v) => $q->where('delivery_status', $v))
            ->with(['customer:id,name'])
            ->select('order_taking_orders.*')
            ->selectSub($sum('quantity_ordered'), 'qty_ordered')
            ->selectSub($sum('quantity_delivered'), 'qty_delivered');

        if ($this->filters['only_pending'] ?? false) {
            $query->whereHas('items', fn (Builder $q) => $q->whereColumn(
                'quantity_delivered', '<', 'quantity_ordered'
            ));
        }

        return $query;
    }

    protected function summaryLabel(): string
    {
        $rows = $this->baseQuery()->get(['id']);
        $ids = $rows->pluck('id');

        if ($ids->isEmpty()) {
            return 'Sin pedidos en el período';
        }

        $totales = OrderItem::query()
            ->whereIn('order_id', $ids)
            ->selectRaw('COALESCE(SUM(quantity_ordered), 0) AS pedido')
            ->selectRaw('COALESCE(SUM(quantity_delivered), 0) AS despachado')
            ->first();

        $pedido = (float) ($totales->pedido ?? 0);
        $despachado = (float) ($totales->despachado ?? 0);
        $pendiente = max(0, $pedido - $despachado);

        return sprintf(
            '%d pedidos · Pedido: %s · Despachado: %s · Pendiente: %s',
            $ids->count(),
            number_format($pedido, 2, ',', '.'),
            number_format($despachado, 2, ',', '.'),
            number_format($pendiente, 2, ',', '.'),
        );
    }

    /**
     * Detalle linea por linea de un pedido, para el modal "Detalle".
     *
     * @return list<array{descripcion:string,pedido:float,despachado:float,pendiente:float}>
     */
    public function lineasDe(int $orderId): array
    {
        return OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('line_number')
            ->get(['description', 'quantity_ordered', 'quantity_delivered'])
            ->map(fn ($item) => [
                'descripcion' => (string) $item->description,
                'pedido' => (float) $item->quantity_ordered,
                'despachado' => (float) $item->quantity_delivered,
                'pendiente' => max(0, round((float) $item->quantity_ordered - (float) $item->quantity_delivered, 2)),
            ])
            ->all();
    }
}
