<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Account;
use App\Models\JournalEntryLine;
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
use Illuminate\Support\Facades\DB;

class GeneralLedgerPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Libro Mayor';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Libro Mayor';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'account_id' => null,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Filtros')
                    ->columns(4)
                    ->schema([
                        Forms\Components\Select::make('account_id')
                            ->label('Cuenta')
                            ->relationship('account', 'name', fn (Builder $query) => $query
                                ->where('accepts_movements', true)->orderBy('code'))
                            ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                            ->searchable(['code', 'name'])
                            ->preload()
                            ->required()
                            ->live()
                            ->columnSpan(2),

                        Forms\Components\DatePicker::make('from')
                            ->label('Desde')->required()->live(),

                        Forms\Components\DatePicker::make('to')
                            ->label('Hasta')->required()->live(),

                        Forms\Components\Placeholder::make('initial_balance_display')
                            ->label('Saldo inicial')
                            ->content(fn () => '$ '.number_format($this->initialBalance(), 2)),

                        Forms\Components\Placeholder::make('period_debit_display')
                            ->label('Total débito período')
                            ->content(fn () => '$ '.number_format($this->periodTotal('debit'), 2)),

                        Forms\Components\Placeholder::make('period_credit_display')
                            ->label('Total crédito período')
                            ->content(fn () => '$ '.number_format($this->periodTotal('credit'), 2)),

                        Forms\Components\Placeholder::make('ending_balance_display')
                            ->label('Saldo final')
                            ->content(fn () => '$ '.number_format(
                                $this->initialBalance() + $this->periodTotal('debit') - $this->periodTotal('credit'),
                                2
                            )),
                    ]),
            ])
            ->statePath('filters');
    }

    protected function initialBalance(): float
    {
        $accountId = $this->filters['account_id'] ?? null;
        $from = $this->filters['from'] ?? null;

        if (! $accountId || ! $from) {
            return 0;
        }

        return (float) JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
    }

    protected function periodTotal(string $column): float
    {
        $accountId = $this->filters['account_id'] ?? null;

        if (! $accountId) {
            return 0;
        }

        return (float) JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('date', '>=', $this->filters['from'])
                ->whereDate('date', '<=', $this->filters['to']))
            ->sum($column);
    }

    public function table(Table $table): Table
    {
        $initial = $this->initialBalance();

        return $table
            ->query(function () use ($initial) {
                $accountId = $this->filters['account_id'] ?? null;
                if (! $accountId) {
                    return JournalEntryLine::query()->whereRaw('1 = 0');
                }

                return JournalEntryLine::query()
                    ->select([
                        'journal_entry_lines.*',
                        DB::raw($initial.' + SUM(debit - credit) OVER (ORDER BY journal_entries.date, journal_entries.id, journal_entry_lines.line_number) AS running_balance'),
                    ])
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->where('account_id', $accountId)
                    ->where('journal_entries.status', 'posted')
                    ->whereDate('journal_entries.date', '>=', $this->filters['from'])
                    ->whereDate('journal_entries.date', '<=', $this->filters['to'])
                    ->orderBy('journal_entries.date')
                    ->orderBy('journal_entries.id')
                    ->orderBy('journal_entry_lines.line_number')
                    ->with(['entry', 'thirdParty']);
            })
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('entry.date')->label('Fecha')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('entry.full_number')
                    ->label('Asiento')
                    ->state(fn (JournalEntryLine $record) => $record->entry?->fullNumber())
                    ->fontFamily('mono')
                    ->url(fn (JournalEntryLine $record) => $record->entry
                        ? route('filament.app.resources.journal-entries.view', $record->entry)
                        : null),
                Tables\Columns\TextColumn::make('thirdParty.name')->label('Tercero')->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('description')->label('Detalle')->limit(60)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('debit')->label('Débito')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('credit')->label('Crédito')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('running_balance')
                    ->label('Saldo')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),
            ]);
    }
}
