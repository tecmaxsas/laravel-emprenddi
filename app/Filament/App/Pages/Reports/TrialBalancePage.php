<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Account;
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

class TrialBalancePage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Balance de Comprobación';

    protected static ?string $navigationGroup = 'Reportes contables';

    protected static ?string $title = 'Balance de Comprobación';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('reports.trial_balance');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'level' => 4,
            'only_with_movements' => true,
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('reports.export.trial_balance', [
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'level' => $this->filters['level'] ?? 4,
            'only_with_movements' => ($this->filters['only_with_movements'] ?? true) ? '1' : '0',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Filtros')
                    ->columns(4)
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                        Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),
                        Forms\Components\Select::make('level')
                            ->label('Nivel máximo')
                            ->options([
                                1 => '1 — Clase',
                                2 => '2 — Grupo',
                                3 => '3 — Cuenta',
                                4 => '4 — Subcuenta',
                                5 => '5 — Auxiliar',
                            ])
                            ->default(4)
                            ->required()
                            ->live(),
                        Forms\Components\Toggle::make('only_with_movements')
                            ->label('Solo cuentas con movimiento')
                            ->default(true)
                            ->live(),
                    ]),
            ])
            ->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
                $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();
                $level = (int) ($this->filters['level'] ?? 4);
                $onlyWithMovements = (bool) ($this->filters['only_with_movements'] ?? true);

                $companyId = auth()->user()->company_id;

                $initialSub = DB::table('journal_entry_lines')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->where('journal_entries.company_id', $companyId)
                    ->where('journal_entries.status', 'posted')
                    ->whereDate('journal_entries.date', '<', $from)
                    ->select('journal_entry_lines.account_id')
                    ->selectRaw('COALESCE(SUM(debit), 0) AS initial_debit, COALESCE(SUM(credit), 0) AS initial_credit')
                    ->groupBy('journal_entry_lines.account_id');

                $periodSub = DB::table('journal_entry_lines')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->where('journal_entries.company_id', $companyId)
                    ->where('journal_entries.status', 'posted')
                    ->whereDate('journal_entries.date', '>=', $from)
                    ->whereDate('journal_entries.date', '<=', $to)
                    ->select('journal_entry_lines.account_id')
                    ->selectRaw('COALESCE(SUM(debit), 0) AS period_debit, COALESCE(SUM(credit), 0) AS period_credit')
                    ->groupBy('journal_entry_lines.account_id');

                $query = Account::query()
                    ->leftJoinSub($initialSub, 'ini', 'ini.account_id', '=', 'accounts.id')
                    ->leftJoinSub($periodSub, 'per', 'per.account_id', '=', 'accounts.id')
                    ->where('accounts.level', '<=', $level)
                    ->select([
                        'accounts.*',
                        DB::raw('COALESCE(ini.initial_debit, 0) - COALESCE(ini.initial_credit, 0) AS initial_balance'),
                        DB::raw('COALESCE(per.period_debit, 0) AS period_debit'),
                        DB::raw('COALESCE(per.period_credit, 0) AS period_credit'),
                        DB::raw('COALESCE(ini.initial_debit, 0) - COALESCE(ini.initial_credit, 0) + COALESCE(per.period_debit, 0) - COALESCE(per.period_credit, 0) AS ending_balance'),
                    ])
                    ->orderBy('accounts.code');

                if ($onlyWithMovements) {
                    $query->where(function (Builder $q) {
                        $q->where(DB::raw('COALESCE(per.period_debit, 0)'), '>', 0)
                          ->orWhere(DB::raw('COALESCE(per.period_credit, 0)'), '>', 0)
                          ->orWhere(DB::raw('COALESCE(ini.initial_debit, 0)'), '>', 0)
                          ->orWhere(DB::raw('COALESCE(ini.initial_credit, 0)'), '>', 0);
                    });
                }

                return $query;
            })
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight(fn (Account $record) => $record->level <= 2 ? 'bold' : 'normal'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Cuenta')
                    ->wrap()
                    ->weight(fn (Account $record) => $record->level <= 2 ? 'bold' : 'normal'),

                Tables\Columns\TextColumn::make('initial_balance')->label('Saldo inicial')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('period_debit')->label('Débito período')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('period_credit')->label('Crédito período')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('ending_balance')
                    ->label('Saldo final')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),
            ]);
    }
}
