<?php

namespace App\Http\Controllers\App;

use App\Filament\App\Pages\Reports\FinancialIndicatorsPage;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
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

    // ============================================================
    // REPORTES TABULARES — Cartera, CxP, Ventas, Stock, Cierres (batch 2)
    // ============================================================

    public function accountsReceivable(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.accounts_receivable'), 403);

        $asOf = $this->parseDate($request->query('as_of'), now()->toDateString());
        $thirdPartyId = (int) $request->query('third_party_id') ?: null;
        $onlyOverdue = filter_var($request->query('only_overdue', '0'), FILTER_VALIDATE_BOOL);
        $includePaid = filter_var($request->query('include_paid', '0'), FILTER_VALIDATE_BOOL);

        $rows = SaleInvoice::query()
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->whereDate('date', '<=', $asOf)
            ->when(! $includePaid, fn (Builder $q) => $q->whereIn('payment_status', ['pendiente', 'parcial', 'vencido']))
            ->when($onlyOverdue, fn (Builder $q) => $q
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $asOf)
                ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido']))
            ->when($thirdPartyId, fn (Builder $q) => $q->where('third_party_id', $thirdPartyId))
            ->with(['customer:id,name,document_number', 'location:id,name'])
            ->orderBy('due_date')
            ->get()
            ->map(function (SaleInvoice $i) use ($asOf) {
                $daysOverdue = null;
                if ($i->due_date) {
                    $diff = $i->due_date->diffInDays(\Carbon\Carbon::parse($asOf), false);
                    $daysOverdue = $diff > 0 ? (int) $diff : 0;
                }
                return [
                    $i->fullNumber(),
                    $i->customer?->name ?: '',
                    $i->customer?->document_number ?: '',
                    $i->date?->format('Y-m-d') ?: '',
                    $i->due_date?->format('Y-m-d') ?: '',
                    $daysOverdue ?? '',
                    (float) $i->total,
                    (float) $i->paid_amount,
                    (float) $i->balance,
                    SaleInvoice::PAYMENT_STATUSES[$i->payment_status] ?? $i->payment_status,
                ];
            });

        $subtitle = "Corte al: {$asOf}".($onlyOverdue ? ' · Solo vencidas' : '').($includePaid ? ' · Incluye pagadas' : '');

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Cartera — Cuentas por Cobrar',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Factura', 'Cliente', 'Documento', 'Fecha', 'Vence', 'Días vencido', 'Total', 'Pagado', 'Saldo', 'Estado'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'string', 'number', 'number', 'number', 'string'],
            ),
            "cartera-{$asOf}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function accountsPayable(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.accounts_receivable'), 403);

        $asOf = $this->parseDate($request->query('as_of'), now()->toDateString());
        $thirdPartyId = (int) $request->query('third_party_id') ?: null;
        $onlyOverdue = filter_var($request->query('only_overdue', '0'), FILTER_VALIDATE_BOOL);
        $includePaid = filter_var($request->query('include_paid', '0'), FILTER_VALIDATE_BOOL);

        $rows = PurchaseInvoice::query()
            ->where('status', PurchaseInvoice::STATUS_POSTED)
            ->whereDate('date', '<=', $asOf)
            ->when(! $includePaid, fn (Builder $q) => $q->whereIn('payment_status', ['pendiente', 'parcial', 'vencido']))
            ->when($onlyOverdue, fn (Builder $q) => $q
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $asOf)
                ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido']))
            ->when($thirdPartyId, fn (Builder $q) => $q->where('third_party_id', $thirdPartyId))
            ->with(['supplier:id,name,document_number', 'location:id,name'])
            ->orderBy('due_date')
            ->get()
            ->map(function (PurchaseInvoice $i) use ($asOf) {
                $daysOverdue = null;
                if ($i->due_date) {
                    $diff = $i->due_date->diffInDays(\Carbon\Carbon::parse($asOf), false);
                    $daysOverdue = $diff > 0 ? (int) $diff : 0;
                }
                return [
                    $i->fullNumber(),
                    $i->supplier_invoice_number ?: '',
                    $i->supplier?->name ?: '',
                    $i->supplier?->document_number ?: '',
                    $i->date?->format('Y-m-d') ?: '',
                    $i->due_date?->format('Y-m-d') ?: '',
                    $daysOverdue ?? '',
                    (float) $i->total,
                    (float) $i->paid_amount,
                    (float) $i->balance,
                    PurchaseInvoice::PAYMENT_STATUSES[$i->payment_status] ?? $i->payment_status,
                ];
            });

        $subtitle = "Corte al: {$asOf}".($onlyOverdue ? ' · Solo vencidas' : '').($includePaid ? ' · Incluye pagadas' : '');

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Cuentas por Pagar',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Factura', 'N° prov.', 'Proveedor', 'Documento', 'Fecha', 'Vence', 'Días vencido', 'Total', 'Pagado', 'Saldo', 'Estado'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'number', 'number', 'number', 'string'],
            ),
            "cuentas-por-pagar-{$asOf}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function salesByPeriod(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.sales'), 403);

        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());
        $locationId = (int) $request->query('location_id') ?: null;
        $sellerUserId = (int) $request->query('seller_user_id') ?: null;

        $rows = SaleInvoice::query()
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($locationId, fn (Builder $q) => $q->where('location_id', $locationId))
            ->when($sellerUserId, fn (Builder $q) => $q->where('seller_user_id', $sellerUserId))
            ->with(['customer:id,name,document_number', 'location:id,name', 'seller:id,name'])
            ->orderBy('date', 'desc')
            ->get()
            ->map(fn (SaleInvoice $i) => [
                $i->fullNumber(),
                $i->date?->format('Y-m-d') ?: '',
                $i->customer?->name ?: '',
                $i->customer?->document_number ?: '',
                $i->location?->name ?: '',
                $i->seller?->name ?: '',
                (float) $i->subtotal,
                (float) $i->tax_total,
                (float) $i->total,
                (float) $i->paid_amount,
                SaleInvoice::PAYMENT_STATUSES[$i->payment_status] ?? $i->payment_status,
            ]);

        $subtitle = "Período: {$from} a {$to}";

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Ventas por Período',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['Número', 'Fecha', 'Cliente', 'Documento', 'Sede', 'Vendedor', 'Subtotal', 'IVA', 'Total', 'Pagado', 'Pago'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'string', 'number', 'number', 'number', 'number', 'string'],
            ),
            "ventas-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function stockByLocation(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.kardex'), 403);

        $categoryId = (int) $request->query('category_id') ?: null;
        $onlyWithStock = filter_var($request->query('only_with_stock', '0'), FILTER_VALIDATE_BOOL);

        $companyId = Auth::user()->company_id;

        $locations = Location::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // Matriz de saldos [product_id => [location_id => balance]]
        $matrixRows = DB::table('inventory_movements')
            ->select('product_id', 'location_id', 'balance_quantity_after')
            ->where('company_id', $companyId)
            ->orderBy('product_id')
            ->orderBy('location_id')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->distinct('product_id', 'location_id')
            ->get();

        $matrix = [];
        foreach ($matrixRows as $r) {
            $matrix[(int) $r->product_id][(int) $r->location_id] = (float) $r->balance_quantity_after;
        }

        $products = Product::query()
            ->where('track_inventory', true)
            ->where('active', true)
            ->when($categoryId, fn (Builder $q) => $q->where('category_id', $categoryId))
            ->with(['category:id,name'])
            ->orderBy('name')
            ->get();

        $rows = $products
            ->filter(function (Product $p) use ($matrix, $onlyWithStock) {
                if (! $onlyWithStock) return true;
                return ! empty(array_filter($matrix[$p->id] ?? [], fn ($v) => $v > 0));
            })
            ->map(function (Product $p) use ($matrix, $locations) {
                $row = [
                    $p->code,
                    $p->name,
                    $p->category?->name ?: '',
                ];
                $total = 0.0;
                foreach ($locations as $loc) {
                    $bal = (float) ($matrix[$p->id][$loc->id] ?? 0);
                    $row[] = $bal;
                    $total += $bal;
                }
                $row[] = $total;
                return $row;
            });

        $headers = ['Código', 'Producto', 'Categoría'];
        $types = ['string', 'string', 'string'];
        foreach ($locations as $loc) {
            $headers[] = $loc->name;
            $types[] = 'number';
        }
        $headers[] = 'Total';
        $types[] = 'number';

        $subtitle = 'Sedes: '.$locations->pluck('name')->implode(', ').($onlyWithStock ? ' · Solo con stock > 0' : '');

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Stock por Sede',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: $headers,
                rows: $rows,
                columnTypes: $types,
            ),
            'stock-por-sede-'.now()->format('Y-m-d').'.xlsx',
            $this->xlsxHeaders(),
        );
    }

    public function cashClosings(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.cash_closings'), 403);

        $from = $this->parseDate($request->query('from'), now()->startOfMonth()->toDateString());
        $to = $this->parseDate($request->query('to'), now()->endOfMonth()->toDateString());
        $locationId = (int) $request->query('location_id') ?: null;
        $cashierUserId = (int) $request->query('cashier_user_id') ?: null;
        $onlyWithDifference = filter_var($request->query('only_with_difference', '0'), FILTER_VALIDATE_BOOL);

        $rows = CashRegisterSession::query()
            ->where('status', CashRegisterSession::STATUS_CLOSED)
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to)
            ->when($locationId, fn (Builder $q) => $q->where('location_id', $locationId))
            ->when($cashierUserId, fn (Builder $q) => $q->where('cashier_user_id', $cashierUserId))
            ->when($onlyWithDifference, fn (Builder $q) => $q
                ->whereRaw('ABS(COALESCE(closing_difference, 0)) >= 0.01'))
            ->with(['cashier:id,name', 'location:id,name', 'closedBy:id,name'])
            ->orderBy('closed_at', 'desc')
            ->get()
            ->map(fn (CashRegisterSession $s) => [
                $s->id,
                $s->location?->name ?: '',
                $s->cashier?->name ?: '',
                $s->opened_at?->format('Y-m-d H:i') ?: '',
                $s->closed_at?->format('Y-m-d H:i') ?: '',
                (float) $s->opening_amount,
                (float) $s->total_sales,
                (int) $s->invoice_count,
                (float) $s->closing_expected,
                (float) $s->closing_counted,
                (float) $s->closing_difference,
                (string) ($s->closing_notes ?? ''),
            ]);

        $subtitle = "Período cierres: {$from} a {$to}".($onlyWithDifference ? ' · Solo con diferencia' : '');

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Cierres de Caja',
                subtitle: $subtitle,
                companyName: $this->companyName(),
                headers: ['#', 'Sede', 'Cajero', 'Abrió', 'Cerró', 'Apertura', 'Ventas', '# Fact.', 'Esperado', 'Contado', 'Diferencia', 'Notas'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'number', 'number', 'number', 'number', 'number', 'number', 'string'],
            ),
            "cierres-caja-{$from}-a-{$to}.xlsx",
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
