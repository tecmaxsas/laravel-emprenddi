<?php

namespace Tests\Feature;

use App\Services\ThirdParties\ThirdPartyImportEngine;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Leer fechas y montos de un archivo de terceros.
 *
 * Dos fallos distintos de la misma causa: dar por hecho que una celda llega
 * como texto.
 *
 * **La fecha rompía la importación entera.** Una celda con formato de fecha no
 * llega como string sino como objeto, y el motor la casteaba: «Object of class
 * DateTimeImmutable could not be converted to string», sin decir fila ni
 * columna.
 *
 * **El monto era peor, porque no rompía nada.** Cuando la celda es texto —cosa
 * que pasa siempre que alguien pega datos de otro sistema— `(float)` de PHP
 * convierte «1.500.000» en 1.5 y «$ 2.196.000» en 0. La importación decía
 * «listo» y el saldo de apertura quedaba mal. Eso no se descubre al importar:
 * se descubre semanas después, cuando el cliente reclama su estado de cuenta, y
 * para entonces nadie lo relaciona.
 *
 * No toca red ni base de datos: es lectura de valores.
 */
class ThirdPartyImportValuesTest extends TestCase
{
    private function parseMoney(mixed $valor): ?float
    {
        $metodo = new ReflectionMethod(ThirdPartyImportEngine::class, 'parseMoney');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(ThirdPartyImportEngine::class), $valor);
    }

    private function normalizar(mixed $valor): mixed
    {
        $metodo = new ReflectionMethod(ThirdPartyImportEngine::class, 'normalizarCelda');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(ThirdPartyImportEngine::class), $valor);
    }

    // ------------------------------------------------------------ fechas

    /** El objeto de fecha se vuelve texto en vez de tumbar la importación. */
    public function test_una_celda_de_fecha_no_rompe_la_importacion(): void
    {
        $this->assertSame('2026-09-16',
            $this->normalizar(new \DateTimeImmutable('2026-09-16 00:00:00')));

        $this->assertSame('2026-09-16',
            $this->normalizar(new \DateTime('2026-09-16 13:45:00')));
    }

    /** Lo que ya era texto se queda como estaba. */
    public function test_lo_que_no_es_fecha_pasa_intacto(): void
    {
        $this->assertSame('ACME SAS', $this->normalizar('ACME SAS'));
        $this->assertSame(2196000, $this->normalizar(2196000));
        $this->assertNull($this->normalizar(null));
    }

    // ------------------------------------------------------------- montos

    /** Los valores tal como se ven en el archivo del usuario. */
    public function test_los_montos_del_archivo_real(): void
    {
        $this->assertSame(2196000.0, $this->parseMoney(2196000));
        $this->assertSame(0.0, $this->parseMoney('$ 0'));
        $this->assertSame(12892500.0, $this->parseMoney(12892500));
        $this->assertSame(13617700.0, $this->parseMoney('13617700'));
    }

    /**
     * El caso que corrompía datos en silencio.
     *
     * Estos tres son el mismo número escrito como lo escribe la gente, y los
     * tres daban basura: 1.5, 1.0 y 0.0 respectivamente.
     */
    public function test_los_formatos_que_daban_basura(): void
    {
        $this->assertSame(1500000.0, $this->parseMoney('1.500.000'),
            'Antes entraba como 1.5 — un millón y medio convertido en peso y medio.');

        $this->assertSame(1500000.0, $this->parseMoney('1,500,000'),
            'Antes entraba como 1.0.');

        $this->assertSame(2196000.0, $this->parseMoney('$ 2.196.000'),
            'Antes entraba como 0 — y un cero no se nota.');
    }

    /** Las dos convenciones de decimales dan el mismo número. */
    public function test_las_dos_convenciones_de_decimales(): void
    {
        $this->assertSame(1500.5, $this->parseMoney('1.500,50'), 'Colombiana.');
        $this->assertSame(1500.5, $this->parseMoney('1,500.50'), 'Inglesa.');
    }

    /**
     * Tres dígitos detrás del separador son miles, no decimales.
     *
     * Es la única ambigüedad real de todo esto: «1.500» puede ser mil quinientos
     * o uno coma quinientos. En Colombia, y en una columna de pesos, es lo
     * primero.
     */
    public function test_tres_digitos_detras_son_miles(): void
    {
        $this->assertSame(1500.0, $this->parseMoney('1.500'));
        $this->assertSame(1500.0, $this->parseMoney('1,500'));

        // Con otra cantidad de dígitos sí es decimal.
        $this->assertSame(1.5, $this->parseMoney('1.5'));
        $this->assertSame(10.5, $this->parseMoney('10,50'));
    }

    /** Contabilidad escribe los negativos entre paréntesis. */
    public function test_los_negativos_en_sus_dos_formas(): void
    {
        $this->assertSame(-500000.0, $this->parseMoney('-500.000'));
        $this->assertSame(-500000.0, $this->parseMoney('(500.000)'));
    }

    /** El espacio duro que pega Excel no estorba. */
    public function test_el_espacio_duro_de_excel(): void
    {
        $this->assertSame(1500000.0, $this->parseMoney("\u{00A0}1.500.000\u{00A0}"));
        $this->assertSame(1500000.0, $this->parseMoney('COP 1.500.000'));
    }

    /** Vacío es vacío, no cero. */
    public function test_vacio_no_es_cero(): void
    {
        $this->assertNull($this->parseMoney(null));
        $this->assertNull($this->parseMoney(''));
    }

    /**
     * Lo que no se entiende se reporta, no se convierte en cero.
     *
     * Meter un cero inventado es exactamente lo que hacía el código viejo, y es
     * la razón por la que el error pasaba desapercibido.
     */
    public function test_lo_ilegible_no_se_vuelve_cero(): void
    {
        $this->assertNull($this->parseMoney('por definir'));
        $this->assertNull($this->parseMoney('N/A'));
        $this->assertNull($this->parseMoney('-'));
    }

    /** Un cero de verdad sí es cero. */
    public function test_un_cero_de_verdad_es_cero(): void
    {
        $this->assertSame(0.0, $this->parseMoney(0));
        $this->assertSame(0.0, $this->parseMoney('0'));
        $this->assertSame(0.0, $this->parseMoney('$ 0'));
    }
}
