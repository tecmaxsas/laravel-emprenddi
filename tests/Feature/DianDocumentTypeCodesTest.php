<?php

namespace Tests\Feature;

use App\Models\CreditDebitNote;
use App\Models\Dian\Resolution;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los códigos de tipo de documento son los de la DIAN, no una lista nuestra.
 *
 * `dian_resolutions.document_type_id` viaja tal cual al proveedor tecnológico al
 * registrar la resolución, y cada documento se transmite con el código DIAN que
 * le corresponde. Son dos sitios que **tienen que decir el mismo número**, y
 * nada los obligaba.
 *
 * La lista era una numeración propia (1..6) donde solo el 1 coincidía con la
 * DIAN por casualidad. Por eso las facturas funcionaban: la resolución de notas
 * quedaba registrada allá como tipo 2 —Factura de Exportación para la DIAN— y la
 * nota se enviaba declarando su tipo 4. El proveedor no encontraba resolución y
 * respondía «La resolución no está configurada», con el rango vacío.
 *
 * Nada en ese mensaje apuntaba a un desajuste de catálogos, y costó cuatro
 * rondas encontrarlo. Estas pruebas existen para que no haya una quinta.
 */
class DianDocumentTypeCodesTest extends TestCase
{
    /**
     * Los códigos oficiales de la DIAN para los documentos que emite Emprenddi.
     *
     * Escritos a mano y no leídos de la constante: si alguien cambia la
     * constante por error, una prueba que la lea a ella diría que todo está
     * bien. Esta es la lista contra la que hay que contrastar.
     */
    private const CODIGOS_DIAN = [
        1 => 'Factura Electrónica',
        2 => 'Factura de Exportación',
        4 => 'Nota Crédito',
        5 => 'Nota Débito',
        9 => 'Nómina Electrónica',
        11 => 'Documento Soporte',
    ];

    /** El catálogo del modelo usa los códigos de la DIAN. */
    public function test_el_catalogo_usa_los_codigos_de_la_dian(): void
    {
        $this->assertSame(
            array_keys(self::CODIGOS_DIAN),
            array_keys(Resolution::DOCUMENT_TYPES),
            'Un código que no es el de la DIAN registra la resolución bajo un tipo '
            .'distinto del que el documento declara al enviarse.');
    }

    /**
     * El tipo con el que se transmite una nota es el mismo con el que se busca
     * su resolución.
     *
     * Este es el desajuste exacto que rompía el envío.
     */
    public function test_la_nota_se_transmite_con_el_tipo_de_su_resolucion(): void
    {
        $credito = new CreditDebitNote(['type' => CreditDebitNote::TYPE_CREDIT]);
        $debito = new CreditDebitNote(['type' => CreditDebitNote::TYPE_DEBIT]);

        $this->assertSame(4, $credito->dianTypeDocumentId());
        $this->assertSame(5, $debito->dianTypeDocumentId());

        // Y esos mismos códigos tienen que existir en el catálogo, porque son
        // los que se usan para buscar la resolución de la empresa.
        $this->assertArrayHasKey(4, Resolution::DOCUMENT_TYPES);
        $this->assertArrayHasKey(5, Resolution::DOCUMENT_TYPES);

        $this->assertSame('Nota Crédito', Resolution::DOCUMENT_TYPES[4]);
        $this->assertSame('Nota Débito', Resolution::DOCUMENT_TYPES[5]);
    }

    /** Los tipos de numeración global siguen siendo los correctos. */
    public function test_los_tipos_globales_apuntan_a_los_codigos_nuevos(): void
    {
        $globales = Resolution::DOCUMENT_TYPES_GLOBALES;

        // Nota crédito, nota débito, nómina y documento soporte: un solo
        // consecutivo para toda la empresa.
        $this->assertContains(4, $globales, 'Nota crédito.');
        $this->assertContains(5, $globales, 'Nota débito.');
        $this->assertContains(9, $globales, 'Nómina.');
        $this->assertContains(11, $globales, 'Documento soporte.');

        // La facturación va por establecimiento: la DIAN autoriza esos rangos
        // por punto de venta.
        $this->assertNotContains(1, $globales, 'La factura electrónica se asigna a la sede.');
        $this->assertNotContains(2, $globales, 'La de exportación también.');

        foreach ($globales as $codigo) {
            $this->assertArrayHasKey($codigo, Resolution::DOCUMENT_TYPES,
                "El tipo global {$codigo} no existe en el catálogo.");
        }
    }

    /**
     * No pueden quedar resoluciones con los códigos viejos.
     *
     * La migración las remapeó. Si aparece una, es que alguien volvió a
     * guardarlas con la lista anterior.
     */
    public function test_no_quedan_resoluciones_con_los_codigos_viejos(): void
    {
        $viejos = DB::table('dian_resolutions')
            ->whereNotIn('document_type_id', array_keys(self::CODIGOS_DIAN))
            ->pluck('document_type_id')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], $viejos,
            'Hay resoluciones con tipos que la DIAN no reconoce: '.implode(', ', $viejos));
    }

    /** El nombre guardado corresponde al código, no al de la lista vieja. */
    public function test_el_nombre_guardado_corresponde_al_codigo(): void
    {
        $desajustadas = [];

        foreach (DB::table('dian_resolutions')->get(['id', 'document_type_id', 'document_type_name']) as $fila) {
            $esperado = self::CODIGOS_DIAN[$fila->document_type_id] ?? null;

            if ($esperado && $fila->document_type_name && $fila->document_type_name !== $esperado) {
                $desajustadas[] = "#{$fila->id}: dice «{$fila->document_type_name}» y es «{$esperado}»";
            }
        }

        $this->assertSame([], $desajustadas,
            "El nombre no corresponde al tipo:\n".implode("\n", $desajustadas));
    }
}
