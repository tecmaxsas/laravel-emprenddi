<?php

namespace App\Http\Controllers\App;

use App\Filament\App\Pages\Reports\FinancialIndicatorsPage;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Reports\FinancialReportExporter;
use App\Services\Reports\FinancialStatementsEngine;
use App\Services\Reports\TabularReportExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Genera y descarga los estados financieros en XLSX. La generacion se hace
 * via FinancialStatementsEngine + FinancialReportExporter. Cada accion exige
 * autenticacion (middleware web+auth) y el permiso del reporte correspondiente.
 */
class ReportExportController extends Controller
{
    public function __construct(
        protected FinancialStatementsEngine $engine,
        protected FinancialReportExporter $exporter,
        protected TabularReportExporter $tabular,
    ) {}

    public function incomeStatement(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.income_statement'), 403);

        $companyId = Auth::user()->company_id;
        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());

        $data = $this->engine->incomeStatement($companyId, $from, $to);
        $name = $this->companyName();

        return response()->streamDownload(
            $this->exporter->streamIncomeStatement($data, $name),
            'estado-resultados-' . $from . '-a-' . $to . '.xlsx',
            $this->xlsxHeaders(),
        );
    }

    public function balanceSheet(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.balance_sheet'), 403);

        $companyId = Auth::user()->company_id;
        $asOf = $this->parseDate($request->query('as_of'), now()->endOfMonth()->toDateString());

        $data = $this->engine->balanceSheet($companyId, $asOf);
        $name = $this->companyName();

        return response()->streamDownload(
            $this->exporter->streamBalanceSheet($data, $name),
            'balance-general-' . $asOf . '.xlsx',
            $this->xlsxHeaders(),
        );
    }

    public function indicators(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.financial_indicators'), 403);

        $from = $this->parseDate($request->query('from'), now()->startOfYear()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->toDateString());

        // Reusar el calculo del page (mismos thresholds/labels) para que el
        // archivo refleje exactamente lo que el usuario ve en pantalla.
        $page = new FinancialIndicatorsPage();
        $reflection = new \ReflectionClass($page);

        $companyId = Auth::user()->company_id;
        $eri = $this->engine->incomeStatement($companyId, $from, $to);
        $esf = $this->engine->balanceSheet($companyId, $to);

        $method = $reflection->getMethod('computeIndicators');
        $method->setAccessible(true);
        $indicators = $method->invoke($page, $eri, $esf);

        return response()->streamDownload(
            $this->exporter->streamIndicators($indicators, $this->companyName(), $from, $to),
            'indicadores-financieros-' . $from . '-a-' . $to . '.xlsx',
            $this->xlsxHeaders(),
        );
    }

    // ============================================================
    // REPORTES TABULARES — Libros DIAN + Kardex
    // ============================================================

    public function journalBook(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.journal_book'), 403);

        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());
        $type = $request->query('type') ?: null;

        $rows = JournalEntry::query()
            ->where('status', 'posted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($type, fn (Builder $q) => $q->where('type', $type))
            ->with(['thirdParty:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (JournalEntry $e) => [
                $e->date->format('Y-m-d'),
                $e->fullNumber(),
                JournalEntry::TYPES[$e->type] ?? $e->type,
                $e->reference ?: '',
                $e->thirdParty?->name ?: '',
                $e->description ?: '',
                (float) $e->total_debit,
                (float) $e->total_credit,
            ]);

        $subtitle = "Período: {$from} a {$to}".($type ? " · Tipo: ".(JournalEntry::TYPES[$type] ?? $type) : '');

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Libro Diario',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Fecha', 'Asiento', 'Tipo', 'Ref.', 'Tercero', 'Concepto', 'Débito', 'Crédito'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'string', 'number', 'number'],
            ),
            "libro-diario-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function generalLedger(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.general_ledger'), 403);

        $accountId = (int) $request->query('account_id');
        abort_if($accountId <= 0, 422, 'Falta el parámetro account_id.');

        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());
        $costCenterId = (int) $request->query('cost_center_id') ?: null;
        $thirdPartyId = (int) $request->query('third_party_id') ?: null;

        $account = Account::find($accountId);
        abort_if(! $account, 404);

        // Saldo inicial (debit-credit antes de 'from')
        $initial = (float) JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->when($costCenterId, fn ($q) => $q->where('cost_center_id', $costCenterId))
            ->when($thirdPartyId, fn ($q) => $q->where('third_party_id', $thirdPartyId))
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS b')
            ->value('b');

        // Movimientos del periodo + saldo corriente con window function
        $lines = JournalEntryLine::query()
            ->select([
                'journal_entry_lines.*',
                DB::raw($initial.' + SUM(debit - credit) OVER (ORDER BY journal_entries.date, journal_entries.id, journal_entry_lines.line_number) AS running_balance'),
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('account_id', $accountId)
            ->when($costCenterId, fn ($q) => $q->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->when($thirdPartyId, fn ($q) => $q->where('journal_entry_lines.third_party_id', $thirdPartyId))
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.date', '>=', $from)
            ->whereDate('journal_entries.date', '<=', $to)
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.line_number')
            ->with(['entry:id,date,prefix,number', 'thirdParty:id,name', 'costCenter:id,code'])
            ->get();

        // Fila de saldo inicial primero
        $rows = collect([['', 'SALDO INICIAL', '', '', '', 0.0, 0.0, $initial]]);

        foreach ($lines as $l) {
            $rows->push([
                $l->entry?->date?->format('Y-m-d') ?? '',
                $l->entry?->fullNumber() ?? '',
                $l->thirdParty?->name ?? '',
                $l->costCenter?->code ?? '',
                (string) ($l->description ?? ''),
                (float) $l->debit,
                (float) $l->credit,
                (float) $l->running_balance,
            ]);
        }

        $subtitle = "Cuenta: {$account->code} · {$account->name} · Período: {$from} a {$to}";

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Libro Mayor y Auxiliar',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Fecha', 'Asiento', 'Tercero', 'C. Costo', 'Detalle', 'Débito', 'Crédito', 'Saldo'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'number', 'number', 'number'],
            ),
            "libro-mayor-{$account->code}-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function trialBalance(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.trial_balance'), 403);

        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());
        $level = (int) ($request->query('level') ?: 4);
        $onlyWithMovements = filter_var($request->query('only_with_movements', '1'), FILTER_VALIDATE_BOOL);

        $companyId = Auth::user()->company_id;

        $initialSub = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.date', '<', $from)
            ->select('journal_entry_lines.account_id')
            ->selectRaw('COALESCE(SUM(debit), 0) AS id, COALESCE(SUM(credit), 0) AS ic')
            ->groupBy('journal_entry_lines.account_id');

        $periodSub = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.date', '>=', $from)
            ->whereDate('journal_entries.date', '<=', $to)
            ->select('journal_entry_lines.account_id')
            ->selectRaw('COALESCE(SUM(debit), 0) AS pd, COALESCE(SUM(credit), 0) AS pc')
            ->groupBy('journal_entry_lines.account_id');

        $query = Account::query()
            ->leftJoinSub($initialSub, 'ini', 'ini.account_id', '=', 'accounts.id')
            ->leftJoinSub($periodSub, 'per', 'per.account_id', '=', 'accounts.id')
            ->where('accounts.level', '<=', $level)
            ->select([
                'accounts.code',
                'accounts.name',
                DB::raw('COALESCE(ini.id, 0) - COALESCE(ini.ic, 0) AS initial_balance'),
                DB::raw('COALESCE(per.pd, 0) AS period_debit'),
                DB::raw('COALESCE(per.pc, 0) AS period_credit'),
                DB::raw('COALESCE(ini.id, 0) - COALESCE(ini.ic, 0) + COALESCE(per.pd, 0) - COALESCE(per.pc, 0) AS ending_balance'),
            ])
            ->orderBy('accounts.code');

        if ($onlyWithMovements) {
            $query->where(function (Builder $q) {
                $q->where(DB::raw('COALESCE(per.pd, 0)'), '>', 0)
                  ->orWhere(DB::raw('COALESCE(per.pc, 0)'), '>', 0)
                  ->orWhere(DB::raw('COALESCE(ini.id, 0)'), '>', 0)
                  ->orWhere(DB::raw('COALESCE(ini.ic, 0)'), '>', 0);
            });
        }

        $rows = $query->get()->map(fn ($r) => [
            $r->code,
            $r->name,
            (float) $r->initial_balance,
            (float) $r->period_debit,
            (float) $r->period_credit,
            (float) $r->ending_balance,
        ]);

        $subtitle = "Período: {$from} a {$to} · Nivel: {$level}".($onlyWithMovements ? ' · Solo cuentas con movimiento' : '');

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Balance de Comprobación',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Código', 'Cuenta', 'Saldo inicial', 'Débito período', 'Crédito período', 'Saldo final'],
                rows: $rows,
                columnTypes: ['string', 'string', 'number', 'number', 'number', 'number'],
            ),
            "balance-comprobacion-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function kardex(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.kardex'), 403);

        $productId = (int) $request->query('product_id');
        $locationId = (int) $request->query('location_id');
        abort_if($productId <= 0 || $locationId <= 0, 422, 'Faltan product_id y/o location_id.');

        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());
        $types = array_filter(explode(',', (string) $request->query('types', '')));

        $product = \App\Models\Product::find($productId);
        $location = \App\Models\Location::find($locationId);
        abort_if(! $product || ! $location, 404);

        $rows = InventoryMovement::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when(! empty($types), fn ($q) => $q->whereIn('type', $types))
            ->with(['thirdParty:id,name', 'journalEntry:id,prefix,number'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (InventoryMovement $m) => [
                $m->date?->format('Y-m-d H:i') ?? '',
                InventoryMovement::TYPES[$m->type] ?? $m->type,
                $m->reference_number ?: '',
                $m->thirdParty?->name ?: '',
                (string) ($m->description ?? ''),
                $m->isEntry() ? abs((float) $m->quantity) : '',
                $m->isExit() ? abs((float) $m->quantity) : '',
                (float) $m->unit_cost,
                (float) $m->total_cost,
                (float) $m->balance_quantity_after,
                (float) $m->balance_value_after,
            ]);

        $subtitle = "Producto: {$product->code} · {$product->name} · Sede: {$location->name} · Período: {$from} a {$to}";

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Kardex de Inventario',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Fecha', 'Tipo', 'Ref.', 'Tercero', 'Concepto', 'Entrada', 'Salida', 'Costo unit.', 'Total', 'Saldo qty', 'Saldo $'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'number', 'number', 'number', 'number', 'number', 'number'],
            ),
            "kardex-{$product->code}-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    protected function parseDate(?string $value, string $fallback): string
    {
        try {
            return $value ? \Carbon\Carbon::parse($value)->toDateString() : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    protected function companyName(): string
    {
        return Auth::user()?->company?->name ?? 'Empresa';
    }

    protected function xlsxHeaders(): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
    }
}
