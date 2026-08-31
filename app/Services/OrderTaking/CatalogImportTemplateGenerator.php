<?php

namespace App\Services\OrderTaking;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Genera las plantillas XLSX del catalogo de Toma pedidos.
 *
 * No existian: el formato salio de los archivos que mando el cliente y nadie
 * sabia que columnas esperaba el importador. De ahi vino el archivo con las
 * columnas de base e IVA vacias, que se importo en silencio y dejo las listas
 * de precios en cero.
 *
 * Son DOS archivos, no uno con dos hojas, porque el importador lee siempre la
 * PRIMERA hoja de cada archivo: precios y clientes se suben por separado. Por
 * lo mismo, los datos van en la hoja 1 y las instrucciones en la 2.
 *
 * La primera columna de la hoja de precios va vacia a proposito: el importador
 * lee la descripcion en la segunda y el codigo en la tercera, porque asi
 * venian los archivos originales. Se respeta para que las plantillas que ya
 * circulan sigan sirviendo.
 *
 * El consumidor de estas plantillas es MacDulcesImporter.
 */
class CatalogImportTemplateGenerator
{
    public const TIPO_PRECIOS = 'precios';

    public const TIPO_CLIENTES = 'clientes';

    /** Hoja de precios — orden exacto que lee el importador. */
    public const PRICE_COLUMNS = [
        '(no usar)',
        'DESCRIPCION',
        'REFERENCIA',
        'LISTA (1-4)',
        'PRECIO TOTAL (con IVA)',
        'BASE (sin IVA)',
        'IVA',
    ];

    /** Hoja de clientes — orden exacto que lee el importador. */
    public const CUSTOMER_COLUMNS = [
        'NOMBRE',
        'NIT / DOCUMENTO',
        '(no usar)',
        '(no usar)',
        'CONTACTO',
        'TELEFONO',
        'CELULAR',
        'LISTA (1-4)',
        '(no usar)',
        'CIUDAD',
        'DIRECCION',
        '(no usar)',
        'FORMA DE PAGO',
        'HORARIO DE RECIBO',
        'RETENCION (%)',
    ];

    public function stream(string $tipo): callable
    {
        return match ($tipo) {
            self::TIPO_PRECIOS => fn () => $this->escribir(
                'Listas de precios',
                self::PRICE_COLUMNS,
                $this->ejemplosDePrecios(),
                $this->instruccionesDePrecios(),
            ),
            self::TIPO_CLIENTES => fn () => $this->escribir(
                'Clientes',
                self::CUSTOMER_COLUMNS,
                $this->ejemplosDeClientes(),
                $this->instruccionesDeClientes(),
            ),
            default => throw new RuntimeException("Plantilla desconocida: {$tipo}"),
        };
    }

    public function nombreArchivo(string $tipo): string
    {
        $sufijo = $tipo === self::TIPO_PRECIOS ? 'listas-de-precios' : 'clientes';

        return "plantilla-{$sufijo}-toma-pedidos-".now()->format('Y-m-d').'.xlsx';
    }

    /**
     * @param  list<array<int, mixed>>  $ejemplos
     * @param  list<string>  $instrucciones
     */
    protected function escribir(string $hoja, array $columnas, array $ejemplos, array $instrucciones): void
    {
        $writer = new Writer(new Options);
        $writer->openToFile('php://output');

        // Hoja 1: los datos. El importador lee esta y solo esta.
        $writer->getCurrentSheet()->setName($hoja);
        $writer->addRow(Row::fromValues($columnas, (new Style)->setFontBold()));

        foreach ($ejemplos as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }

        // Hoja 2: como llenarla. Va despues para no desplazar los datos.
        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        foreach ($instrucciones as $i => $linea) {
            $writer->addRow(Row::fromValues([$linea], $i === 0 ? (new Style)->setFontBold() : new Style));
        }

        $writer->close();
    }

    /** @return list<array<int, mixed>> */
    protected function ejemplosDePrecios(): array
    {
        // Mismo producto en dos listas, y un exento: los tres casos que
        // confunden al llenarla.
        return [
            ['', 'BOLA ACIDA OJOS BOLSA * 100', 'MG-67', 1, 152520, 128160, 24360],
            ['', 'BOLA ACIDA OJOS BOLSA * 100', 'MG-67', 2, 160000, 134454, 25546],
            ['', 'PRODUCTO EXENTO DE EJEMPLO', 'EX-01', 1, 50000, 50000, 0],
        ];
    }

    /** @return list<array<int, mixed>> */
    protected function ejemplosDeClientes(): array
    {
        return [[
            'DISTRIBUIDORA DE DULCES NATALY SAS', '901234567', '', '',
            'Nataly Gómez', '6011234567', '3001234567', 1, '',
            'BOGOTA', 'CR 78G 48B 14 SUR', '',
            'CREDITO 30 DIAS', 'LUN-VIER 8 A 12 Y 2 A 5 PM', 0.414,
        ]];
    }

    /** @return list<string> */
    protected function instruccionesDePrecios(): array
    {
        return [
            'CÓMO LLENAR LA HOJA "Listas de precios"',
            '',
            'Los datos van en la PRIMERA hoja. No la reordenes ni la renombres:',
            'el importador lee esa y solo esa.',
            '',
            'UNA FILA POR PRODUCTO Y POR LISTA',
            '  Un producto que está en las 4 listas ocupa 4 filas, con la misma',
            '  REFERENCIA y distinto número de lista.',
            '',
            'REFERENCIA es la llave',
            '  Es el código del producto. Si ya existe en el sistema se actualiza',
            '  su precio; nunca se crea uno duplicado. Por eso puedes reimportar',
            '  para corregir precios sin miedo.',
            '',
            'LISTA va de 1 a 4. Otro valor y la fila se ignora.',
            '',
            'LAS TRES COLUMNAS DE PRECIO SON OBLIGATORIAS',
            '  BASE + IVA tiene que dar PRECIO TOTAL.',
            '  Si no cuadra, la importación se rechaza entera y te dice qué',
            '  productos están mal. No se importa nada a medias.',
            '',
            '  Con IVA 19%:  base 128.160 + IVA 24.360 = total 152.520',
            '  Exento:       base  50.000 + IVA      0 = total  50.000',
            '',
            '  Dejar BASE e IVA en cero NO significa exento: significa que el',
            '  archivo está incompleto, y por eso se rechaza.',
            '',
            'COLUMNAS "(no usar)"',
            '  Déjalas vacías. Están para que las posiciones cuadren con las',
            '  plantillas originales; el importador no las lee.',
            '',
            'BORRA LAS FILAS DE EJEMPLO ANTES DE SUBIR EL ARCHIVO.',
        ];
    }

    /** @return list<string> */
    protected function instruccionesDeClientes(): array
    {
        return [
            'CÓMO LLENAR LA HOJA "Clientes"',
            '',
            'Los datos van en la PRIMERA hoja. No la reordenes ni la renombres:',
            'el importador lee esa y solo esa.',
            '',
            'ESTE ARCHIVO ES OPCIONAL',
            '  Si solo vas a corregir precios, no lo subas. Importarlo reescribe',
            '  los datos de tus clientes: la lista de precios asignada, las',
            '  condiciones de pago y el horario de recibo.',
            '',
            'NIT / DOCUMENTO es la llave',
            '  Si el tercero ya existe se actualiza; no se duplica.',
            '',
            'LISTA (1-4)',
            '  La lista de precios que se le asigna por defecto al cliente. Es la',
            '  que se carga sola al tomarle un pedido.',
            '',
            'RETENCION (%)',
            '  Porcentaje informativo. Las retenciones que se le aplican de verdad',
            '  al facturar se configuran en Terceros → pestaña Fiscal, donde se',
            '  eligen del catálogo de impuestos con su tarifa y su cuenta.',
            '',
            'COLUMNAS "(no usar)"',
            '  Déjalas vacías. Están para que las posiciones cuadren con las',
            '  plantillas originales; el importador no las lee.',
            '',
            'BORRA LA FILA DE EJEMPLO ANTES DE SUBIR EL ARCHIVO.',
        ];
    }
}
