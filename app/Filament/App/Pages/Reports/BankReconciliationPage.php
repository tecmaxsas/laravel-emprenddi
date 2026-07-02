<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Account;
use App\Models\JournalEntryLine;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación bancaria por marcado manual. El contador filtra por
 * cuenta bancaria + rango, ve cada línea contable que toca esa cuenta,
 * y marca con un toggle las que ya aparecen en el extracto del banco.
 * El saldo del extracto se digita arriba; el sistema muestra:
 *   saldo libros   = sum(debit - credit) sobre TODAS las líneas
 *   saldo conciliado = sum(debit - credit) sobre líneas marcadas
 *   pendientes en libros = saldo libros - saldo conciliado
 *   diferencia con extracto = saldo extracto - saldo conciliado
 */
class BankReconciliationPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Conciliación bancaria';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Conciliación Bancaria';

    protected static ?int $navigationSort = 65;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('reports.general_ledger');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'account_id' => null,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'show_reconciled' => false,
            'statement_closing_balance' => 0,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('account_id')
                        ->label('Cuenta bancaria')
                        ->required()
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '11%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName())
                        ->helperText('Cuentas 1105 Caja, 1110 Bancos, 1120 Cuentas de ahorro, etc.')
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),

                    Forms\Components\TextInput::make('statement_closing_balance')
                        ->label('Saldo según extracto del banco')
                        ->numeric()
                        ->prefix('$')
                        ->default(0)
                        ->live(onBlur: true)
                        ->helperText('Digita aquí el saldo final del extracto bancario para ver la diferencia.')
                        ->columnSpan(2),

                    Forms\Components\Toggle::make('show_reconciled')
                        ->label('Mostrar líneas ya conciliadas')
                        ->default(false)
                        ->live()
                        ->columnSpan(2),

                    Forms\Components\Placeholder::make('book_balance_display')
                        ->label('Saldo en libros')
                        ->content(fn () => '$ '.number_format($this->bookBalance(), 2)),

                    Forms\Components\Placeholder::make('reconciled_balance_display')
                        ->label('Saldo conciliado')
                        ->content(fn () => '$ '.number_format($this->reconciledBalance(), 2)),

                    Forms\Components\Placeholder::make('pending_in_books_display')
                        ->label('Pendiente por conciliar (libros)')
                        ->content(fn () => '$ '.number_format($this->bookBalance() - $this->reconciledBalance(), 2)),

                    Forms\Components\Placeholder::make('statement_diff_display')
                        ->label('Diferencia con extracto')
                        ->content(function () {
                            $diff = (float) ($this->filters['statement_closing_balance'] ?? 0) - $this->reconciledBalance();
                            $color = abs($diff) < 0.01 ? 'rgb(5,150,105)' : 'rgb(220,38,38)';
                            return new \Illuminate\Support\HtmlString(sprintf(
                                '<span style="font-weight:600; color:%s;">$ %s</span>',
                                $color,
                                number_format($diff, 2),
                            ));
                        }),
                ]),
        ])->statePath('filters');
    }

    protected function bookBalance(): float
    {
        $accountId = $this->filters['account_id'] ?? null;
        if (! $accountId) return 0;

        return (float) JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('date', '>=', $this->filters['from'])
                ->whereDate('date', '<=', $this->filters['to']))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
    }

    protected function reconciledBalance(): float
    {
        $accountId = $this->filters['account_id'] ?? null;
        if (! $accountId) return 0;

        return (float) JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->where('bank_reconciled', true)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('date', '>=', $this->filters['from'])
                ->whereDate('date', '<=', $this->filters['to']))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $accountId = $this->filters['account_id'] ?? null;
                $showReconciled = $this->filters['show_reconciled'] ?? false;

                if (! $accountId) {
                    return JournalEntryLine::query()->whereRaw('1 = 0');
                }

                // CRITICO multitenancy: JournalEntryLine no lleva company_id
                // en la tabla, asi que un raw join no dispara el global scope
                // de JournalEntry (Eloquent no lo aplica en joins raw).
                // Sin este guard, un accountId de otra empresa vaciaba el
                // libro mayor ajeno; peor: las acciones de bulk reconcile
                // podian ESCRIBIR bank_reconciled en filas de otro tenant.
                return JournalEntryLine::query()
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->where('journal_entries.company_id', auth()->user()?->company_id)
                    ->where('journal_entry_lines.account_id', $accountId)
                    ->where('journal_entries.status', 'posted')
                    ->whereDate('journal_entries.date', '>=', $this->filters['from'])
                    ->whereDate('journal_entries.date', '<=', $this->filters['to'])
                    ->when(! $showReconciled, fn ($q) => $q->where('journal_entry_lines.bank_reconciled', false))
                    ->orderBy('journal_entries.date')
                    ->orderBy('journal_entries.id')
                    ->orderBy('journal_entry_lines.line_number')
                    ->select('journal_entry_lines.*')
                    ->with(['entry', 'thirdParty']);
            })
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('entry.date')->label('Fecha')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('entry.full_number')
                    ->label('Asiento')
                    ->state(fn (JournalEntryLine $r) => $r->entry?->fullNumber())
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('entry.reference')->label('Ref.')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('thirdParty.name')->label('Tercero')->wrap()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('description')->label('Detalle')->wrap()->limit(80)->placeholder('—'),
                Tables\Columns\TextColumn::make('debit')->label('Débito')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('credit')->label('Crédito')->money('COP')->alignEnd(),
                Tables\Columns\IconColumn::make('bank_reconciled')
                    ->label('Conciliado')
                    ->boolean()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('bank_reference')->label('Ref. banco')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('bank_reconciled_at')->label('Conciliado el')->dateTime('Y-m-d H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('reconcile')
                    ->label('Conciliar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (JournalEntryLine $r) => ! $r->bank_reconciled)
                    ->form([
                        Forms\Components\TextInput::make('bank_reference')
                            ->label('Referencia del extracto (opcional)')
                            ->maxLength(100)
                            ->placeholder('Ej. 4567 — Movimiento del extracto'),
                    ])
                    ->action(function (JournalEntryLine $r, array $data) {
                        $r->update([
                            'bank_reconciled' => true,
                            'bank_reconciled_at' => now(),
                            'bank_reconciled_by_user_id' => Auth::id(),
                            'bank_reference' => $data['bank_reference'] ?? null,
                        ]);
                        Notification::make()->title('Línea conciliada')->success()->send();
                    }),

                Tables\Actions\Action::make('unreconcile')
                    ->label('Quitar conciliación')
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->visible(fn (JournalEntryLine $r) => (bool) $r->bank_reconciled)
                    ->requiresConfirmation()
                    ->action(function (JournalEntryLine $r) {
                        $r->update([
                            'bank_reconciled' => false,
                            'bank_reconciled_at' => null,
                            'bank_reconciled_by_user_id' => null,
                            'bank_reference' => null,
                        ]);
                        Notification::make()->title('Conciliación removida')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('reconcileBulk')
                    ->label('Marcar como conciliadas')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        DB::transaction(function () use ($records) {
                            foreach ($records as $line) {
                                if (! $line->bank_reconciled) {
                                    $line->update([
                                        'bank_reconciled' => true,
                                        'bank_reconciled_at' => now(),
                                        'bank_reconciled_by_user_id' => Auth::id(),
                                    ]);
                                }
                            }
                        });
                        Notification::make()->title("{$records->count()} línea(s) conciliada(s)")->success()->send();
                    }),
            ]);
    }
}
