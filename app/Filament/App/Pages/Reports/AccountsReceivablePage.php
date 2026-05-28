<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\SaleInvoice;
use App\Models\ThirdParty;
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

class AccountsReceivablePage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Cartera (CxC)';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Cartera — Cuentas por Cobrar';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('reports.accounts_receivable');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'as_of' => now()->toDateString(),
            'third_party_id' => null,
            'only_overdue' => false,
            'include_paid' => false,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('as_of')
                        ->label('Corte a')
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->placeholder('Todos')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('is_customer', true)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                  ->orWhere('document_number', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => "{$t->document_number} — {$t->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($v) => ThirdParty::find($v)?->name)
                        ->live(),

                    Forms\Components\Toggle::make('only_overdue')
                        ->label('Solo vencidas')
                        ->live(),

                    Forms\Components\Toggle::make('include_paid')
                        ->label('Incluir pagadas')
                        ->helperText('Muestra también facturas con saldo cero (auditoría)')
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $asOf = $this->filters['as_of'] ?? now()->toDateString();

                return SaleInvoice::query()
                    ->where('status', SaleInvoice::STATUS_POSTED)
                    ->whereDate('date', '<=', $asOf)
                    ->when(! ($this->filters['include_paid'] ?? false), function (Builder $q) {
                        $q->whereIn('payment_status', [
                            SaleInvoice::PAYMENT_PENDIENTE ?? 'pendiente',
                            SaleInvoice::PAYMENT_PARCIAL ?? 'parcial',
                            SaleInvoice::PAYMENT_VENCIDO ?? 'vencido',
                        ]);
                    })
                    ->when($this->filters['only_overdue'] ?? false, fn (Builder $q) => $q
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '<', $this->filters['as_of'] ?? now()->toDateString())
                        ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido']))
                    ->when($this->filters['third_party_id'] ?? null, fn (Builder $q, $v) => $q->where('third_party_id', $v))
                    ->with(['customer:id,name,document_number', 'location:id,name']);
            })
            ->defaultSort('due_date', 'asc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Factura')
                    ->state(fn (SaleInvoice $r) => $r->fullNumber())
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->wrap(),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—'),

                Tables\Columns\TextColumn::make('days_overdue')
                    ->label('Días vencido')
                    ->state(function (SaleInvoice $r) {
                        if (! $r->due_date) return null;
                        $asOf = \Carbon\Carbon::parse($this->filters['as_of'] ?? now()->toDateString());
                        $diff = $r->due_date->diffInDays($asOf, false);
                        return $diff > 0 ? (int) $diff : 0;
                    })
                    ->badge()
                    ->color(fn ($state) => $state > 30 ? 'danger' : ($state > 0 ? 'warning' : 'gray'))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('paid_amount')->label('Pagado')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->state(fn (SaleInvoice $r) => $r->balance)
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => SaleInvoice::PAYMENT_STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pendiente' => 'warning',
                        'parcial' => 'info',
                        'pagado' => 'success',
                        'vencido' => 'danger',
                        default => 'gray',
                    }),
            ]);
    }
}
