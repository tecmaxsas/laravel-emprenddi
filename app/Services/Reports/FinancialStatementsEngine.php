<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Motor de calculo de los Estados Financieros bajo NIIF/PUC colombiano.
 *
 * Produce las estructuras de:
 *  - Estado de Resultados Integral (ERI / P&G)
 *  - Estado de Situacion Financiera (ESF / Balance General)
 *
 * Trabaja sobre journal_entry_lines de asientos posted. Usa los prefijos
 * de codigo del PUC para clasificar:
 *   1xx Activos · 2xx Pasivos · 3xx Patrimonio
 *   4xx Ingresos · 5xx Gastos · 6xx Costo de ventas · 7xx Costo de produccion
 *
 * Las paginas (IncomeStatement, BalanceSheet, FinancialIndicators) consumen
 * este servicio.
 */
class FinancialStatementsEngine
{
    /**
     * Calcula el Estado de Resultados Integral del periodo [from, to].
     * Retorna totales por seccion + desglose por cuenta para mostrar.
     */
    public function incomeStatement(int $companyId, string $from, string $to): array
    {
        $accounts = $this->fetchAccountMovements($companyId, $from, $to, ['4', '5', '6', '7']);

        $revenueOperating   = $this->sumByPrefix($accounts, ['41']);
        $revenueNonOp       = $this->sumByPrefix($accounts, ['42']);
        $cogs               = $this->sumByPrefix($accounts, ['61', '62', '7']);
        $opexAdmin          = $this->sumByPrefix($accounts, ['51']);
        $opexSales          = $this->sumByPrefix($accounts, ['52']);
        $opexNonOp          = $this->sumByPrefix($accounts, ['53']);
        $financialExpenses  = $this->sumByPrefix($accounts, ['5305']);
        $tax                = $this->sumByPrefix($accounts, ['54']);

        $grossProfit     = $revenueOperating - $cogs;
        $operatingResult = $grossProfit - $opexAdmin - $opexSales;
        $beforeTax       = $operatingResult + $revenueNonOp - $opexNonOp;
        $net             = $beforeTax - $tax;
        $ori             = 0.0; // ORI requiere registros NIIF especificos (Iter futuro)
        $integral        = $net + $ori;

        return [
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'revenue_operating'  => $revenueOperating,
                'cogs'               => $cogs,
                'gross_profit'       => $grossProfit,
                'opex_admin'         => $opexAdmin,
                'opex_sales'         => $opexSales,
                'operating_result'   => $operatingResult,
                'revenue_non_op'     => $revenueNonOp,
                'opex_non_op'        => $opexNonOp,
                'financial_expenses' => $financialExpenses,
                'before_tax'         => $beforeTax,
                'tax'                => $tax,
                'net_result'         => $net,
                'ori'                => $ori,
                'integral_result'    => $integral,
            ],
            'sections' => [
                'revenue_operating' => $this->detailByPrefix($accounts, ['41']),
                'cogs'              => $this->detailByPrefix($accounts, ['61', '62', '7']),
                'opex_admin'        => $this->detailByPrefix($accounts, ['51']),
                'opex_sales'        => $this->detailByPrefix($accounts, ['52']),
                'revenue_non_op'    => $this->detailByPrefix($accounts, ['42']),
                'opex_non_op'       => $this->detailByPrefix($accounts, ['53']),
                'tax'               => $this->detailByPrefix($accounts, ['54']),
            ],
        ];
    }

    /**
     * Calcula el Estado de Situacion Financiera a la fecha 'asOf'.
     * Incluye como linea extra "Resultado del ejercicio" para que
     * Activos = Pasivos + Patrimonio (la utilidad del año aun no se ha
     * cerrado contra 36xx durante el ejercicio en curso).
     */
    public function balanceSheet(int $companyId, string $asOf): array
    {
        $accounts = $this->fetchAccountMovements($companyId, null, $asOf, ['1', '2', '3']);

        $assetsCurrent    = $this->detailByPrefix($accounts, ['11', '12', '13', '14', '17']);
        $assetsNonCurrent = $this->detailByPrefix($accounts, ['15', '16', '18', '19']);
        $liabCurrent      = $this->detailByPrefix($accounts, ['21', '22', '23', '24', '25', '26']);
        $liabNonCurrent   = $this->detailByPrefix($accounts, ['27', '28']);
        $equityAccounts   = $this->detailByPrefix($accounts, ['3']);

        $assetsCurrentTotal    = array_sum(array_column($assetsCurrent, 'balance'));
        $assetsNonCurrentTotal = array_sum(array_column($assetsNonCurrent, 'balance'));
        $assetsTotal           = $assetsCurrentTotal + $assetsNonCurrentTotal;

        $liabCurrentTotal    = array_sum(array_column($liabCurrent, 'balance'));
        $liabNonCurrentTotal = array_sum(array_column($liabNonCurrent, 'balance'));
        $liabTotal           = $liabCurrentTotal + $liabNonCurrentTotal;

        $equityBooked = array_sum(array_column($equityAccounts, 'balance'));

        // Resultado del año (desde 1 de enero del año de asOf hasta asOf)
        $yearStart = date('Y-01-01', strtotime($asOf));
        $yearResult = $this->incomeStatement($companyId, $yearStart, $asOf)['totals']['net_result'];

        $equityTotal = $equityBooked + $yearResult;
        $liabAndEquityTotal = $liabTotal + $equityTotal;

        return [
            'as_of' => $asOf,
            'assets' => [
                'current'           => $assetsCurrent,
                'current_total'     => $assetsCurrentTotal,
                'non_current'       => $assetsNonCurrent,
                'non_current_total' => $assetsNonCurrentTotal,
                'total'             => $assetsTotal,
            ],
            'liabilities' => [
                'current'           => $liabCurrent,
                'current_total'     => $liabCurrentTotal,
                'non_current'       => $liabNonCurrent,
                'non_current_total' => $liabNonCurrentTotal,
                'total'             => $liabTotal,
            ],
            'equity' => [
                'accounts'        => $equityAccounts,
                'booked_total'    => $equityBooked,
                'year_result'     => $yearResult,
                'total'           => $equityTotal,
            ],
            'liab_and_equity_total' => $liabAndEquityTotal,
            'difference' => round($assetsTotal - $liabAndEquityTotal, 2),
        ];
    }

    // ============================================================
    // INTERNOS
    // ============================================================

    /**
     * Trae el balance por cuenta filtrando por clases (primer digito del codigo)
     * y rango de fechas. $from null = desde el origen (para balance acumulado).
     *
     * @return array<int,array{id:int,code:string,name:string,level:int,nature:string,balance:float}>
     */
    protected function fetchAccountMovements(int $companyId, ?string $from, string $to, array $classes): array
    {
        $classPattern = '(' . implode('|', array_map(fn ($c) => preg_quote($c, '/'), $classes)) . ')';

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereDate('je.date', '<=', $to)
            ->whereRaw("substring(a.code from 1 for 1) ~ '^{$classPattern}$'")
            ->select([
                'a.id',
                'a.code',
                'a.name',
                'a.level',
                'a.nature',
                DB::raw('SUM(jel.debit) AS d'),
                DB::raw('SUM(jel.credit) AS c'),
            ])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.level', 'a.nature');

        if ($from !== null) {
            $query->whereDate('je.date', '>=', $from);
        }

        $rows = $query->get();

        $out = [];
        foreach ($rows as $r) {
            $balance = $r->nature === 'debit'
                ? ((float) $r->d - (float) $r->c)
                : ((float) $r->c - (float) $r->d);

            $out[] = [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'level' => (int) $r->level,
                'nature' => $r->nature,
                'balance' => $balance,
            ];
        }

        return $out;
    }

    /** Suma balances de cuentas cuyo codigo empieza con alguno de los prefijos. */
    protected function sumByPrefix(array $accounts, array $prefixes): float
    {
        $total = 0.0;
        foreach ($accounts as $a) {
            foreach ($prefixes as $p) {
                if (str_starts_with($a['code'], $p)) {
                    $total += $a['balance'];
                    break;
                }
            }
        }
        return round($total, 2);
    }

    /**
     * Lista detallada de cuentas cuyo codigo empieza con alguno de los prefijos,
     * solo las que tienen movimiento, ordenadas por codigo. Nivel <= 4 para
     * no inflar con auxiliares en el informe.
     *
     * @return array<int,array{code:string,name:string,level:int,balance:float}>
     */
    protected function detailByPrefix(array $accounts, array $prefixes): array
    {
        $out = [];
        foreach ($accounts as $a) {
            if (abs($a['balance']) < 0.01) continue;
            if ($a['level'] > 4) continue;
            foreach ($prefixes as $p) {
                if (str_starts_with($a['code'], $p)) {
                    $out[] = [
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'level' => $a['level'],
                        'balance' => round($a['balance'], 2),
                    ];
                    break;
                }
            }
        }
        usort($out, fn ($x, $y) => strcmp($x['code'], $y['code']));
        return $out;
    }
}
