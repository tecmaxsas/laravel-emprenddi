<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El ancho de los campos en las líneas de un documento.
 *
 * Los repeaters de líneas reparten el ancho con una grilla y un columnSpan por
 * campo. Si los spans no suman exactamente el ancho de la grilla, la fila se
 * parte y los campos quedan desalineados entre líneas — sin error, solo se ve
 * mal.
 *
 * Antes la cantidad tenía 1 de 16 (un 6% del ancho) y no se alcanzaba a leer
 * lo que se digitaba. Este test no juzga si un campo es lo bastante ancho —eso
 * es criterio— pero sí que el reparto cuadre.
 */
class InvoiceLineGridTest extends TestCase
{
    private const RECURSOS = [
        'SaleInvoiceResource',
        'PurchaseInvoiceResource',
        'QuotationResource',
        'CreditDebitNoteResource',
    ];

    public function test_los_anchos_de_cada_linea_suman_el_ancho_de_la_grilla(): void
    {
        $problemas = [];

        foreach (self::RECURSOS as $recurso) {
            $codigo = file_get_contents(app_path("Filament/App/Resources/{$recurso}.php"));

            $inicio = strpos($codigo, "Repeater::make('lines')");
            $this->assertNotFalse($inicio, "{$recurso} no tiene repeater de líneas.");

            preg_match("/->columns\(\['default' => \d+, 'md' => (\d+), 'xl' => (\d+)\]\)/",
                substr($codigo, $inicio), $grilla);

            $this->assertNotEmpty($grilla, "{$recurso}: la grilla de líneas no es responsive.");

            $region = substr($codigo, $inicio, strpos($codigo, $grilla[0], $inicio) - $inicio);

            foreach (['md' => 1, 'xl' => 2] as $punto => $indice) {
                preg_match_all("/'{$punto}' => (\d+)\]\)/", $region, $spans);
                $suma = array_sum(array_map('intval', $spans[1]));
                $ancho = (int) $grilla[$indice];

                // En md los campos ocupan varias filas, así que la suma tiene
                // que ser un múltiplo del ancho; en xl, exactamente una fila.
                $cuadra = $punto === 'xl' ? $suma === $ancho : $suma % $ancho === 0;

                if (! $cuadra) {
                    $problemas[] = "{$recurso} ({$punto}): los spans suman {$suma} sobre una grilla de {$ancho}";
                }
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    /** La cantidad es el campo que más se digita: no puede ser el más angosto. */
    public function test_la_cantidad_no_es_el_campo_mas_angosto(): void
    {
        $problemas = [];

        foreach (self::RECURSOS as $recurso) {
            $codigo = file_get_contents(app_path("Filament/App/Resources/{$recurso}.php"));
            $inicio = strpos($codigo, "Repeater::make('lines')");
            $region = substr($codigo, $inicio, 6000);

            preg_match("/->label\('Cant\.'\).*?'xl' => (\d+)\]\)/s", $region, $cantidad);

            if (empty($cantidad) || (int) $cantidad[1] < 2) {
                $problemas[] = $recurso.': la cantidad tiene un ancho de '
                    .($cantidad[1] ?? '?').' y no se alcanza a leer lo que se digita';
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }
}
