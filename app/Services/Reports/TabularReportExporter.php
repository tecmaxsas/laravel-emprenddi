<?php

namespace App\Services\Reports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exportador generico de reportes tabulares a XLSX usando OpenSpout.
 *
 * Distinto de FinancialReportExporter (que arma documentos estructurados):
 * aqui recibimos cabeceras + filas como matriz y escribimos la tabla con
 * estilo basico (titulo, cabecera y formato numerico).
 *
 * Tipos de columna: 'number' aplica formato monetario #,##0; cualquier otro
 * valor se trata como texto.
 */
class TabularReportExporter
{
    /**
     * @param  string  $title       Titulo del reporte (primera fila destacada).
     * @param  string  $subtitle    Linea de contexto (periodo, filtros).
     * @param  string  $companyName Nombre de la empresa para el encabezado.
     * @param  array   $headers     Lista de cabeceras de columna.
     * @param  iterable $rows       Iterable de arrays (un array por fila, mismo orden que $headers).
     * @param  array   $columnTypes ['string'|'number'] por columna. Si se omite, todo string.
     */
    public function stream(
        string $title,
        string $subtitle,
        string $companyName,
        array $headers,
        iterable $rows,
        array $columnTypes = [],
    ): callable {
        return function () use ($title, $subtitle, $companyName, $headers, $rows, $columnTypes) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            $titleStyle  = (new Style())->setFontBold()->setFontSize(14);
            $subtitleStyle = (new Style())->setFontItalic();
            $headerStyle = (new Style())
                ->setFontBold()
                ->setBackgroundColor('334155')
                ->setFontColor('FFFFFF');
            $numberStyle = (new Style())->setFormat('#,##0');

            // Encabezado del documento
            $writer->addRow(Row::fromValues([$companyName])->setStyle($titleStyle));
            $writer->addRow(Row::fromValues([$title])->setStyle((new Style())->setFontBold()));
            if ($subtitle !== '') {
                $writer->addRow(Row::fromValues([$subtitle])->setStyle($subtitleStyle));
            }
            $writer->addRow(Row::fromValues([]));

            // Cabecera de columnas
            $headerCells = array_map(
                fn ($h) => Cell::fromValue($h, $headerStyle),
                $headers,
            );
            $writer->addRow(new Row($headerCells));

            // Filas de datos
            foreach ($rows as $row) {
                $cells = [];
                foreach (array_values((array) $row) as $i => $value) {
                    $type = $columnTypes[$i] ?? 'string';
                    $style = $type === 'number' ? $numberStyle : null;
                    // Normalizar nulos a string vacio para evitar celdas raras
                    if ($value === null) {
                        $value = '';
                    }
                    $cells[] = Cell::fromValue($value, $style);
                }
                $writer->addRow(new Row($cells));
            }

            $writer->close();
        };
    }
}
