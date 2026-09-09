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

        foreach ($this->repetidores() as $nombre => [$region, $grilla]) {
            foreach (['md' => 1, 'xl' => 2] as $punto => $indice) {
                // Ojo con la forma del span: en `['default' => 1, 'md' => 6,
                // 'xl' => 5]` el valor de md no queda al final del arreglo. Una
                // regex que exija `])` justo después no encuentra nada y el
                // test pasa sin haber medido: así estuvo hasta ahora.
                preg_match_all("/'{$punto}' => (\d+)[,\]]/", $region, $spans);
                $suma = array_sum(array_map('intval', $spans[1]));
                $ancho = (int) $grilla[$indice];

                $this->assertNotSame(0, $suma, "{$nombre} ({$punto}): no se leyó ningún columnSpan.");

                // Los campos ocupan varias filas, así que la suma tiene que ser
                // un múltiplo exacto del ancho: si sobra o falta, la última fila
                // queda a medias y los campos se desalinean entre líneas.
                if ($suma % $ancho !== 0) {
                    $problemas[] = "{$nombre} ({$punto}): los spans suman {$suma} sobre una grilla de {$ancho}";
                }

                // En pantalla ancha la línea debe leerse como una fila de tabla,
                // no como un formulario: dos filas es el tope.
                if ($punto === 'xl' && intdiv($suma, $ancho) > 2) {
                    $problemas[] = "{$nombre} (xl): cada fila ocupa ".intdiv($suma, $ancho).' filas';
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

    /**
     * Los campos de plata llevan prefijo «$», separadores y valores de siete
     * cifras. Con menos de la cuarta parte del ancho de la fila el número se
     * corta mientras se escribe: se ve «100(» en vez de «1000000».
     */
    public function test_los_campos_de_plata_tienen_ancho_para_un_valor_largo(): void
    {
        $problemas = [];

        foreach (self::RECURSOS as $recurso) {
            $codigo = file_get_contents(app_path("Filament/App/Resources/{$recurso}.php"));
            $inicio = strpos($codigo, "Repeater::make('lines')");

            preg_match("/->columns\(\['default' => \d+, 'md' => \d+, 'xl' => (\d+)\]\)/",
                substr($codigo, $inicio), $grilla);
            $ancho = (int) $grilla[1];
            $region = substr($codigo, $inicio, strpos($codigo, $grilla[0], $inicio) - $inicio);

            foreach (['Precio unit.', 'Costo unit.'] as $etiqueta) {
                if (! preg_match("/->label\('".preg_quote($etiqueta, '/')."'\).*?'xl' => (\d+)\]\)/s", $region, $campo)) {
                    continue;
                }

                $porcentaje = (int) $campo[1] / $ancho;

                if ($porcentaje < 0.2) {
                    $problemas[] = sprintf('%s: «%s» ocupa %d de %d (%d%%) y el valor se corta al digitarlo',
                        $recurso, $etiqueta, (int) $campo[1], $ancho, round($porcentaje * 100));
                }
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    /**
     * Cada repetidor de las cuatro pantallas, con su región de código y el
     * ancho de su grilla. Se miran también las retenciones: ahí se coló un
     * descuadre que el test anterior no veía porque solo leía las líneas.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    private function repetidores(): array
    {
        $repetidores = [];

        foreach (self::RECURSOS as $recurso) {
            $codigo = file_get_contents(app_path("Filament/App/Resources/{$recurso}.php"));

            foreach (['lines', 'retentions'] as $repetidor) {
                $inicio = strpos($codigo, "Repeater::make('{$repetidor}')");

                if ($inicio === false) {
                    // Cotizaciones y notas no llevan retenciones.
                    $this->assertNotSame('lines', $repetidor, "{$recurso} no tiene repeater de líneas.");

                    continue;
                }

                preg_match("/->columns\(\['default' => \d+, 'md' => (\d+), 'xl' => (\d+)\]\)/",
                    substr($codigo, $inicio), $grilla);

                $this->assertNotEmpty($grilla, "{$recurso} ({$repetidor}): la grilla no es responsive.");

                $repetidores["{$recurso}/{$repetidor}"] = [
                    substr($codigo, $inicio, strpos($codigo, $grilla[0], $inicio) - $inicio),
                    $grilla,
                ];
            }
        }

        return $repetidores;
    }
}
