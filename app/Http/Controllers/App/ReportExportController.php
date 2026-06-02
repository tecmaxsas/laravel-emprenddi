<?php

namespace App\Http\Controllers\App;

use App\Filament\App\Pages\Reports\FinancialIndicatorsPage;
use App\Http\Controllers\Controller;
use App\Services\Reports\FinancialReportExporter;
use App\Services\Reports\FinancialStatementsEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
