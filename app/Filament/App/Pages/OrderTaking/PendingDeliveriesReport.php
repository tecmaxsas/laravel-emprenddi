<?php

namespace App\Filament\App\Pages\OrderTaking;

use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\Product;
use App\Models\ThirdParty;
use App\Services\OrderTaking\PendingDeliveries;
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

/**
 * No entregas: qué se pidió y nunca salió de la bodega.
 *
 * Distinto del informe «Despachos y pendientes», que mira PEDIDOS y responde
 * cuánto le falta a cada uno. Este mira LÍNEAS y responde otra pregunta, que
 * es la que hace el que compra: qué referencia concreta le quedamos debiendo
 * a qué cliente, hace cuántos días y por cuánta plata.
 *
 * Los pedidos cerrados con faltantes siguen apareciendo, y se pueden aislar
 * con el filtro. Es el punto de poder cerrarlos: el pedido deja de estar en
 * curso, pero la mercancía que el cliente pidió y no recibió sigue siendo un
 * faltante. Si al cerrar desaparecieran, cerrar sería una forma de esconder
 * el problema.
 */
class PendingDeliveriesReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'No entregas';

    protected static ?string $navigationGroup = 'Toma pedidos';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'order-taking/pending-deliveries';

    protected static ?string $title = 'No entregas: referencias pendientes';

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
            'product_id' => null,
            'cerrados' => 'todos',
        ];

        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('order-taking.reports.export.pending', $this->filtrosParaUrl());
    }

    public function getPdfUrl(): ?string
    {
        return route('order-taking.reports.pdf.pending', $this->filtrosParaUrl());
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
                            ->orderBy('name')->limit(200)
                            ->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('product_id')
                        ->label('Referencia')->placeholder('Todas')->searchable()->live()
                        ->options(fn () => Product::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')->limit(200)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => trim($p->code.' — '.$p->name, ' —')])
                            ->all()),

                    Forms\Components\Select::make('cerrados')
                        ->label('Pedidos cerrados')->native(false)->live()
                        ->options([
                            'todos' => 'Incluirlos',
                            'excluir' => 'Solo lo que aún se puede despachar',
                            'solo' => 'Solo los cerrados con faltantes',
                        ])
                        ->default('todos')
                        ->selectablePlaceholder(false)
                        ->helperText('Un pedido cerrado dejó de estar en curso, pero su faltante sigue contando.'),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(PendingDeliveries::class)->lineas($this->filtros()))
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('order.order_date')
                    ->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('dias')
                    ->label('Días')
                    ->state(fn (OrderItem $r) => $r->order?->order_date
                        ? (int) $r->order->order_date->copy()->startOfDay()->diffInDays(now()->startOfDay())
                        : 0)
                    ->alignEnd()
                    ->badge()
                    // Lo que lleva esperando es la cifra que convierte un
                    // faltante en un problema: treinta días es otra cosa que
                    // tres.
                    ->color(fn ($state) => match (true) {
                        $state >= 30 => 'danger',
                        $state >= 8 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('pedido')
                    ->label('Pedido')
                    ->state(fn (OrderItem $r) => $r->order?->fullNumber() ?? '—')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('order.customer.name')
                    ->label('Cliente')->limit(28)->placeholder('—')->searchable(),

                Tables\Columns\TextColumn::make('product.code')
                    ->label('Código')->fontFamily('mono')->placeholder('—')->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Referencia')->limit(34)->searchable(),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Pedido')->numeric(decimalPlaces: 2)->alignEnd(),

                Tables\Columns\TextColumn::make('quantity_delivered')
                    ->label('Despachado')->numeric(decimalPlaces: 2)->alignEnd()->color('success'),

                Tables\Columns\TextColumn::make('pendiente')
                    ->label('Pendiente')
                    ->state(fn (OrderItem $r) => $r->pendingQuantity())
                    ->numeric(decimalPlaces: 2)->alignEnd()->weight('bold')->color('warning'),

                Tables\Columns\TextColumn::make('valor')
                    ->label('Valor pendiente')
                    ->state(fn (OrderItem $r) => round($r->pendingQuantity() * (float) $r->unit_price_at_public, 2))
                    ->money('COP')->alignEnd()->weight('bold'),

                Tables\Columns\TextColumn::make('order.status')
                    ->label('Estado')->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state) => match ($state) {
                        Order::STATUS_CLOSED => 'danger',
                        Order::STATUS_PARTIAL_DELIVERED => 'warning',
                        default => 'gray',
                    })
                    // El motivo del cierre es la respuesta a «¿y por qué no se
                    // entregó?», así que se lee sin salir de la fila.
                    ->tooltip(fn (OrderItem $r) => $r->order?->close_reason),
            ])
            ->recordUrl(null)
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->resumen())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->disabled(),
            ]);
    }

    /** @return array<string, mixed> */
    protected function filtros(): array
    {
        $cerrados = $this->filters['cerrados'] ?? 'todos';

        return [
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'third_party_id' => $this->filters['third_party_id'] ?? null,
            'product_id' => $this->filters['product_id'] ?? null,
            'incluir_cerrados' => $cerrados !== 'excluir',
            'solo_cerrados' => $cerrados === 'solo',
        ];
    }

    /** @return array<string, mixed> */
    protected function filtrosParaUrl(): array
    {
        return array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'third_party_id' => $this->filters['third_party_id'] ?? null,
            'product_id' => $this->filters['product_id'] ?? null,
            'cerrados' => $this->filters['cerrados'] ?? 'todos',
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function resumen(): string
    {
        $r = app(PendingDeliveries::class)->resumen($this->filtros());

        return sprintf(
            '%d línea%s · %s unidades · $%s pendientes · %d cliente%s',
            $r['lineas'],
            $r['lineas'] === 1 ? '' : 's',
            number_format($r['unidades'], 2, ',', '.'),
            number_format($r['valor'], 0, ',', '.'),
            $r['clientes'],
            $r['clientes'] === 1 ? '' : 's',
        );
    }
}
