<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\User;
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

class SalesByPeriodPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Ventas por Período';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Ventas por Período';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('reports.sales');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'location_id' => null,
            'seller_user_id' => null,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->placeholder('Todas')
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),

                    Forms\Components\Select::make('seller_user_id')
                        ->label('Vendedor')
                        ->placeholder('Todos')
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
                $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

                return SaleInvoice::query()
                    ->where('status', SaleInvoice::STATUS_POSTED)
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to)
                    ->when($this->filters['location_id'] ?? null, fn (Builder $q, $v) => $q->where('location_id', $v))
                    ->when($this->filters['seller_user_id'] ?? null, fn (Builder $q, $v) => $q->where('seller_user_id', $v))
                    ->with(['customer:id,name,document_number', 'location:id,name', 'seller:id,name']);
            })
            ->defaultSort('date', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (SaleInvoice $r) => $r->fullNumber())
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),
                Tables\Columns\TextColumn::make('seller.name')->label('Vendedor')->toggleable(),
                Tables\Columns\TextColumn::make('subtotal')->label('Subtotal')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('tax_total')->label('IVA')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd()->weight('semibold'),
                Tables\Columns\TextColumn::make('paid_amount')->label('Pagado')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Pago')
                    ->formatStateUsing(fn (string $s) => SaleInvoice::PAYMENT_STATUSES[$s] ?? $s)
                    ->badge()
                    ->color(fn (string $s) => match ($s) {
                        'pendiente' => 'warning',
                        'parcial' => 'info',
                        'pagado' => 'success',
                        'vencido' => 'danger',
                        'cancelada' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->summaryLabel())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->disabled(),
            ]);
    }

    protected function summaryLabel(): string
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;
        if (! $from || ! $to) return 'Total —';

        $q = SaleInvoice::query()
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($this->filters['location_id'] ?? null, fn (Builder $b, $v) => $b->where('location_id', $v))
            ->when($this->filters['seller_user_id'] ?? null, fn (Builder $b, $v) => $b->where('seller_user_id', $v));

        $count = $q->count();
        $total = (float) $q->sum('total');

        return sprintf('Total %d facturas — $%s', $count, number_format($total, 0, ',', '.'));
    }
}
