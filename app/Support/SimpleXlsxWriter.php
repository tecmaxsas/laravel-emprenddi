<?php

namespace App\Support;

use ZipArchive;

/**
 * Generador mínimo de archivos .xlsx (Office Open XML) sin dependencias.
 *
 * Soporta una hoja con celdas de texto y numéricas — suficiente para los
 * exportables del sistema (información exógena, etc.). Para algo más
 * complejo (estilos, fórmulas, varias hojas) conviene una librería.
 */
class SimpleXlsxWriter
{
    /**
     * Construye el .xlsx y devuelve su contenido binario.
     *
     * @param  array<int,array<int,string|int|float|null>>  $rows  Filas; la primera suele ser el encabezado.
     */
    public static function build(array $rows, string $sheetName = 'Hoja1'): string
    {
        $colCount = 0;
        foreach ($rows as $row) {
            $colCount = max($colCount, count($row));
        }

        $parts = [
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rootRels(),
            'xl/workbook.xml' => self::workbook($sheetName),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => self::sheet($rows, $colCount),
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $binary = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    protected static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    protected static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    protected static function workbook(string $sheetName): string
    {
        $name = self::sanitizeSheetName($sheetName);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::esc($name).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    protected static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  array<int,array<int,string|int|float|null>>  $rows
     */
    protected static function sheet(array $rows, int $colCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($colCount > 0) {
            $xml .= '<cols><col min="1" max="'.$colCount.'" width="24"/></cols>';
        }

        $xml .= '<sheetData>';

        $r = 1;
        foreach ($rows as $row) {
            $xml .= '<row r="'.$r.'">';
            $c = 0;
            foreach ($row as $value) {
                $ref = self::colLetter($c).$r;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $text = (string) ($value ?? '');
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                        .self::esc($text).'</t></is></c>';
                }
                $c++;
            }
            $xml .= '</row>';
            $r++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /** Letra(s) de columna a partir de un índice base 0 (0=A, 26=AA). */
    protected static function colLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    protected static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** El nombre de hoja de Excel: máx 31 chars, sin : \ / ? * [ ]. */
    protected static function sanitizeSheetName(string $name): string
    {
        $name = str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name);
        $name = trim($name);

        return mb_substr($name === '' ? 'Hoja1' : $name, 0, 31);
    }
}
