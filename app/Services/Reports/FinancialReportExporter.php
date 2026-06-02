<?php

namespace App\Services\Reports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera archivos XLSX de los estados financieros usando OpenSpout. Las
 * funciones retornan un callable apto para response()->streamDownload(...).
 */
class FinancialReportExporter
{
    /** XLSX del Estado de Resultados Integral. */
    public function streamIncomeStatement(array $data, string $companyName): callable
    {
        return function () use ($data, $companyName) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            $title   = $this->styleTitle();
            $header  = $this->styleHeader();
            $section = $this->styleSection();
            $bold    = $this->styleBold();
            $total   = $this->styleTotal();
            $money   = $this->styleMoney();
            $moneyB  = $this->styleMoneyBold();
            $moneyT  = $this->styleMoneyTotal();
            $detail  = $this->styleDetail();
            $detailM = $this->styleDetailMoney();

            $t = $data['totals'] ?? [];
            $s = $data['sections'] ?? [];
            $p = $data['period'] ?? ['from' => '', 'to' => ''];

            // Encabezado del documento
            $writer->addRow(Row::fromValues([$companyName])->setStyle($title));
            $writer->addRow(Row::fromValues(['Estado de Resultados Integral'])->setStyle($bold));
            $writer->addRow(Row::fromValues(["Período: {$p['from']} — {$p['to']}"]));
            $writer->addRow(Row::fromValues([]));

            // Columnas
            $writer->addRow(new Row([
                Cell::fromValue('Concepto', $header),
                Cell::fromValue('Valor', $header),
            ]));

            $sect = function (string $label, float $value) use ($writer, $section, $moneyB) {
                $writer->addRow(new Row([
                    Cell::fromValue($label, $section),
                    Cell::fromValue($value, $moneyB),
                ]));
            };
            $det = function (string $label, float $value) use ($writer, $detail, $detailM) {
                $writer->addRow(new Row([
                    Cell::fromValue('   '.$label, $detail),
                    Cell::fromValue($value, $detailM),
                ]));
            };
            $boldRow = function (string $label, float $value) use ($writer, $bold, $moneyB) {
                $writer->addRow(new Row([
                    Cell::fromValue($label, $bold),
                    Cell::fromValue($value, $moneyB),
                ]));
            };
            $tot = function (string $label, float $value) use ($writer, $total, $moneyT) {
                $writer->addRow(new Row([
                    Cell::fromValue($label, $total),
                    Cell::fromValue($value, $moneyT),
                ]));
            };

            // INGRESOS
            $sect('Ingresos operacionales', (float) ($t['revenue_operating'] ?? 0));
            foreach ($s['revenue_operating'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], (float) $a['balance']);
            }
            // COSTO
            $sect('(−) Costo de ventas', -(float) ($t['cogs'] ?? 0));
            foreach ($s['cogs'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], -(float) $a['balance']);
            }
            // UTILIDAD BRUTA
            $sect('= Utilidad bruta', (float) ($t['gross_profit'] ?? 0));

            // GASTOS OPERACIONALES
            $boldRow('(−) Gastos de administración (51)', -(float) ($t['opex_admin'] ?? 0));
            foreach ($s['opex_admin'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], -(float) $a['balance']);
            }
            $boldRow('(−) Gastos de ventas (52)', -(float) ($t['opex_sales'] ?? 0));
            foreach ($s['opex_sales'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], -(float) $a['balance']);
            }
            $sect('= Utilidad operacional (EBIT)', (float) ($t['operating_result'] ?? 0));

            // NO OPERACIONALES
            if (($t['revenue_non_op'] ?? 0) > 0) {
                $boldRow('(+) Ingresos no operacionales (42)', (float) $t['revenue_non_op']);
                foreach ($s['revenue_non_op'] ?? [] as $a) {
                    $det($a['code'].' · '.$a['name'], (float) $a['balance']);
                }
            }
            if (($t['opex_non_op'] ?? 0) > 0) {
                $boldRow('(−) Gastos no operacionales (53)', -(float) $t['opex_non_op']);
                foreach ($s['opex_non_op'] ?? [] as $a) {
                    $det($a['code'].' · '.$a['name'], -(float) $a['balance']);
                }
            }

            // ANTES DE IMPUESTOS
            $sect('= Utilidad antes de impuestos', (float) ($t['before_tax'] ?? 0));

            // IMPUESTO
            if (($t['tax'] ?? 0) > 0) {
                $boldRow('(−) Impuesto de renta (54)', -(float) $t['tax']);
            }

            // UTILIDAD NETA
            $tot('UTILIDAD NETA', (float) ($t['net_result'] ?? 0));

            // ORI + INTEGRAL
            $boldRow('(+/-) Otro Resultado Integral (ORI)', (float) ($t['ori'] ?? 0));
            $tot('RESULTADO INTEGRAL TOTAL', (float) ($t['integral_result'] ?? 0));

            $writer->close();
        };
    }

    /** XLSX del Estado de Situacion Financiera. */
    public function streamBalanceSheet(array $data, string $companyName): callable
    {
        return function () use ($data, $companyName) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            $title   = $this->styleTitle();
            $header  = $this->styleHeader();
            $section = $this->styleSection();
            $bold    = $this->styleBold();
            $total   = $this->styleTotal();
            $detail  = $this->styleDetail();
            $detailM = $this->styleDetailMoney();
            $moneyB  = $this->styleMoneyBold();
            $moneyT  = $this->styleMoneyTotal();

            $assets = $data['assets'] ?? [];
            $liab   = $data['liabilities'] ?? [];
            $equity = $data['equity'] ?? [];
            $asOf   = $data['as_of'] ?? '';

            $writer->addRow(Row::fromValues([$companyName])->setStyle($title));
            $writer->addRow(Row::fromValues(['Estado de Situación Financiera'])->setStyle($bold));
            $writer->addRow(Row::fromValues(["Al: {$asOf}"]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(new Row([
                Cell::fromValue('Concepto', $header),
                Cell::fromValue('Valor', $header),
            ]));

            $sect = function (string $l, float $v) use ($writer, $section, $moneyB) {
                $writer->addRow(new Row([Cell::fromValue($l, $section), Cell::fromValue($v, $moneyB)]));
            };
            $det = function (string $l, float $v) use ($writer, $detail, $detailM) {
                $writer->addRow(new Row([Cell::fromValue('   '.$l, $detail), Cell::fromValue($v, $detailM)]));
            };
            $tot = function (string $l, float $v) use ($writer, $total, $moneyT) {
                $writer->addRow(new Row([Cell::fromValue($l, $total), Cell::fromValue($v, $moneyT)]));
            };

            // ACTIVOS
            $writer->addRow(Row::fromValues(['ACTIVOS'])->setStyle($total));
            $sect('Activo corriente', (float) ($assets['current_total'] ?? 0));
            foreach ($assets['current'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], (float) $a['balance']);
            }
            $sect('Activo no corriente', (float) ($assets['non_current_total'] ?? 0));
            foreach ($assets['non_current'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], (float) $a['balance']);
            }
            $tot('TOTAL ACTIVOS', (float) ($assets['total'] ?? 0));
            $writer->addRow(Row::fromValues([]));

            // PASIVOS
            $writer->addRow(Row::fromValues(['PASIVOS'])->setStyle($total));
            $sect('Pasivo corriente', (float) ($liab['current_total'] ?? 0));
            foreach ($liab['current'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], (float) $a['balance']);
            }
            $sect('Pasivo no corriente', (float) ($liab['non_current_total'] ?? 0));
            foreach ($liab['non_current'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], (float) $a['balance']);
            }
            $tot('TOTAL PASIVOS', (float) ($liab['total'] ?? 0));
            $writer->addRow(Row::fromValues([]));

            // PATRIMONIO
            $writer->addRow(Row::fromValues(['PATRIMONIO'])->setStyle($total));
            foreach ($equity['accounts'] ?? [] as $a) {
                $det($a['code'].' · '.$a['name'], (float) $a['balance']);
            }
            $det('+ Resultado del ejercicio (calculado)', (float) ($equity['year_result'] ?? 0));
            $tot('TOTAL PATRIMONIO', (float) ($equity['total'] ?? 0));
            $writer->addRow(Row::fromValues([]));

            $tot('TOTAL PASIVO + PATRIMONIO', (float) ($data['liab_and_equity_total'] ?? 0));

            $diff = (float) ($data['difference'] ?? 0);
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([
                abs($diff) < 1 ? '✓ La ecuación contable cuadra.' : "⚠ Diferencia: {$diff}. Revisa asientos.",
            ]));

            $writer->close();
        };
    }

    /** XLSX de los Indicadores Financieros. */
    public function streamIndicators(array $indicators, string $companyName, string $from, string $to): callable
    {
        return function () use ($indicators, $companyName, $from, $to) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            $title   = $this->styleTitle();
            $header  = $this->styleHeader();
            $section = $this->styleSection();
            $bold    = $this->styleBold();

            $writer->addRow(Row::fromValues([$companyName])->setStyle($title));
            $writer->addRow(Row::fromValues(['Indicadores Financieros'])->setStyle($bold));
            $writer->addRow(Row::fromValues(["Período: {$from} — {$to}"]));
            $writer->addRow(Row::fromValues([]));

            $writer->addRow(new Row([
                Cell::fromValue('Indicador', $header),
                Cell::fromValue('Valor', $header),
                Cell::fromValue('Fórmula', $header),
                Cell::fromValue('Benchmark', $header),
                Cell::fromValue('Estado', $header),
            ]));

            $statusLabels = [
                'good' => 'Saludable',
                'warning' => 'Atención',
                'bad' => 'Crítico',
                'neutral' => 'Informativo',
            ];

            foreach ($indicators as $group) {
                $writer->addRow(new Row([
                    Cell::fromValue($group['title'], $section),
                    Cell::fromValue('', $section),
                    Cell::fromValue('', $section),
                    Cell::fromValue('', $section),
                    Cell::fromValue('', $section),
                ]));

                foreach ($group['items'] as $item) {
                    $value = $this->formatIndicatorValue($item['value'], $item['format']);
                    $writer->addRow(Row::fromValues([
                        $item['name'],
                        $value,
                        $item['formula'],
                        $item['benchmark'],
                        $statusLabels[$item['status']] ?? 'Info',
                    ]));
                }
            }

            $writer->close();
        };
    }

    protected function formatIndicatorValue($v, string $format): string
    {
        if ($format === 'money') return '$ ' . number_format((float) $v, 0, ',', '.');
        if ($format === 'pct')   return number_format((float) $v, 1, ',', '.') . '%';
        if ($format === 'days')  return number_format((float) $v, 0, ',', '.') . ' días';
        return number_format((float) $v, 2, ',', '.');
    }

    // ============================================================
    // ESTILOS
    // ============================================================

    protected function styleTitle(): Style
    {
        return (new Style())->setFontBold()->setFontSize(14);
    }

    protected function styleHeader(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setBackgroundColor('334155')
            ->setFontColor('FFFFFF');
    }

    protected function styleSection(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setBackgroundColor('EEF2FF');
    }

    protected function styleBold(): Style
    {
        return (new Style())->setFontBold();
    }

    protected function styleTotal(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setBackgroundColor('0F172A')
            ->setFontColor('FFFFFF');
    }

    protected function styleMoney(): Style
    {
        return (new Style())->setFormat('#,##0');
    }

    protected function styleMoneyBold(): Style
    {
        return (new Style())->setFontBold()->setFormat('#,##0')->setBackgroundColor('EEF2FF');
    }

    protected function styleMoneyTotal(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFormat('#,##0')
            ->setBackgroundColor('0F172A')
            ->setFontColor('FFFFFF');
    }

    protected function styleDetail(): Style
    {
        return (new Style())->setShouldShrinkToFit(false);
    }

    protected function styleDetailMoney(): Style
    {
        return (new Style())->setFormat('#,##0');
    }
}
