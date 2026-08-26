<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\CommissionEntry;
use App\Models\User;
use App\Support\CommissionsSettings;
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
 * Reporte de comisiones causadas por vendedor en un período. Muestra el
 * detalle de cada comisión (factura, base, monto, estado) con filtros.
 */
class CommissionsReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Comisiones por Vendedor';
    protected static ?string $navigationGroup = 'Reportes operativos';
    protected static ?string $title = 'Comisiones por Vendedor';
    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! CommissionsSettings::moduleActive()) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('commissions.view');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'seller_user_id' => null,
            'status' => null,
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
                    Forms\Components\Select::make('seller_user_id')
                        ->label('Vendedor')
                        ->placeholder('Todos')
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->orderBy('name')->get()
                            ->mapWithKeys(fn ($u) => [$u->id => trim($u->name.' '.$u->last_name)])->all())
                        ->live(),
                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->placeholder('Todos')
                        ->options([
                            CommissionEntry::STATUS_PENDING => 'Pendiente',
                            CommissionEntry::STATUS_SETTLED => 'Liquidada',
                            CommissionEntry::STATUS_REVERSED => 'Reversada',
                        ])
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

                return CommissionEntry::query()
                    ->whereDate('causation_date', '>=', $from)
                    ->whereDate('causation_date', '<=', $to)
                    ->when($this->filters['seller_user_id'] ?? null, fn (Builder $q, $v) => $q->where('seller_user_id', $v))
                    ->when($this->filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
                    ->with(['seller:id,name,last_name', 'saleInvoice:id,prefix,number']);
            })
            ->defaultSort('causation_date', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->formatStateUsing(fn ($record) => trim($record->seller->name.' '.$record->seller->last_name))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('saleInvoice.full_number')
                    ->label('Factura')
                    ->state(fn ($record) => $record->saleInvoice?->fullNumber())
                    ->url(fn ($record) => $record->saleInvoice
                        ? route('filament.app.resources.sale-invoices.view', $record->saleInvoice)
                        : null)
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('causation_date')
                    ->label('Causada')
                    ->dateTime('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('causation_basis')
                    ->label('Causación')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'collected' ? 'Al cobrar' : 'Al facturar')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('base_amount')
                    ->label('Base')
                    ->money('COP')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Comisión')
                    ->money('COP')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        CommissionEntry::STATUS_PENDING => 'Pendiente',
                        CommissionEntry::STATUS_SETTLED => 'Liquidada',
                        CommissionEntry::STATUS_REVERSED => 'Reversada',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        CommissionEntry::STATUS_PENDING => 'warning',
                        CommissionEntry::STATUS_SETTLED => 'success',
                        CommissionEntry::STATUS_REVERSED => 'danger',
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

        $base = CommissionEntry::query()
            ->whereDate('causation_date', '>=', $from)
            ->whereDate('causation_date', '<=', $to)
            ->when($this->filters['seller_user_id'] ?? null, fn (Builder $q, $v) => $q->where('seller_user_id', $v))
            ->when($this->filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v));

        $pending = (float) (clone $base)->where('status', CommissionEntry::STATUS_PENDING)->sum('amount');
        $total = (float) $base->sum('amount');

        return sprintf('Total: $%s · Pendiente de liquidar: $%s',
            number_format($total, 0, ',', '.'),
            number_format($pending, 0, ',', '.'),
        );
    }
}
