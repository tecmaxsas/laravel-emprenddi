<?php

namespace App\Services\Exogena;

/**
 * Catálogo de los 12 formatos de información exógena (medios magnéticos)
 * de la DIAN y sus conceptos.
 *
 * ⚠ La DIAN cambia conceptos y topes (cuantías mínimas) cada año por
 * resolución. Este catálogo recoge los conceptos de uso general; debe
 * contrastarse con la resolución vigente del año a reportar.
 *
 * basis — de dónde sale el dato:
 *   movements → suma de movimientos del año     (1001, 1003, 1005, 1006, 1007)
 *   balance   → saldo de las cuentas al 31-dic  (1008, 1009, 1012)
 *   partners  → se arma desde el Libro de Socios (1010)
 *   manual    → se diligencia a mano             (1004, 1011)
 *
 * side — naturaleza del valor a tomar:
 *   debit  → SUM(débito) − SUM(crédito)
 *   credit → SUM(crédito) − SUM(débito)
 */
class ExogenaCatalog
{
    public static function formats(): array
    {
        return [
            '1001' => [
                'name' => 'Pagos o abonos en cuenta',
                'description' => 'Pagos, costos y deducciones realizados a terceros durante el año gravable.',
                'basis' => 'movements',
                'side' => 'debit',
                'concepts' => [
                    '5001' => 'Salarios, prestaciones sociales y demás pagos laborales',
                    '5002' => 'Honorarios',
                    '5003' => 'Comisiones',
                    '5004' => 'Servicios',
                    '5005' => 'Arrendamientos',
                    '5006' => 'Intereses y rendimientos financieros',
                    '5007' => 'Compra de activos movibles (inventarios y mercancías)',
                    '5008' => 'Compra de activos fijos',
                    '5010' => 'Aportes parafiscales — Cajas de compensación familiar',
                    '5011' => 'Aportes parafiscales — ICBF',
                    '5012' => 'Aportes parafiscales — SENA',
                    '5013' => 'Aportes obligatorios a salud (sistema general de seguridad social)',
                    '5014' => 'Aportes obligatorios al sistema general de pensiones',
                    '5015' => 'Aportes al sistema general de riesgos laborales (ARL)',
                    '5016' => 'Donaciones',
                    '5020' => 'Otros costos, gastos y deducciones',
                    '5023' => 'Aportes voluntarios a pensiones',
                    '5024' => 'Aportes a cuentas AFC / AVC',
                    '5026' => 'Cargos diferidos y gastos pagados por anticipado',
                    '5027' => 'Servicios públicos',
                    '5044' => 'Compra de activos fijos reales productivos',
                    '5055' => 'Impuestos efectivamente pagados (deducibles)',
                    '5058' => 'Devoluciones de pagos de años anteriores',
                ],
            ],

            '1003' => [
                'name' => 'Retenciones en la fuente que le practicaron',
                'description' => 'Retenciones que terceros le practicaron a la empresa durante el año.',
                'basis' => 'movements',
                'side' => 'debit',
                'concepts' => [
                    '1301' => 'Retención por salarios y demás pagos laborales',
                    '1302' => 'Retención por ventas',
                    '1303' => 'Retención por servicios',
                    '1304' => 'Retención por honorarios',
                    '1305' => 'Retención por comisiones',
                    '1306' => 'Retención por arrendamientos',
                    '1307' => 'Retención por rendimientos financieros',
                    '1308' => 'Retención por compras',
                    '1310' => 'Otras retenciones en la fuente a título de renta',
                    '1320' => 'Retención de IVA practicada',
                    '1321' => 'Retención de ICA practicada',
                ],
            ],

            '1004' => [
                'name' => 'Descuentos tributarios solicitados',
                'description' => 'Descuentos tributarios. Se diligencia manualmente con base en la declaración de renta.',
                'basis' => 'manual',
                'side' => 'debit',
                'concepts' => [
                    '8301' => 'Descuentos tributarios solicitados en la declaración de renta',
                ],
            ],

            '1005' => [
                'name' => 'Impuesto sobre las ventas descontable (IVA descontable)',
                'description' => 'IVA descontable por compras y servicios gravados, por tercero.',
                'basis' => 'movements',
                'side' => 'debit',
                'concepts' => [
                    'IVA_DESC' => 'IVA descontable',
                ],
            ],

            '1006' => [
                'name' => 'Impuesto sobre las ventas generado e impuesto al consumo',
                'description' => 'IVA generado e impuesto nacional al consumo, por tercero.',
                'basis' => 'movements',
                'side' => 'credit',
                'concepts' => [
                    'IVA_GEN' => 'IVA generado (impuesto sobre las ventas)',
                    'INC' => 'Impuesto nacional al consumo',
                ],
            ],

            '1007' => [
                'name' => 'Ingresos recibidos',
                'description' => 'Ingresos recibidos durante el año gravable, por tercero y concepto.',
                'basis' => 'movements',
                'side' => 'credit',
                'concepts' => [
                    '4001' => 'Ingresos brutos de actividades ordinarias (operacionales)',
                    '4002' => 'Ingresos financieros',
                    '4003' => 'Ingresos por dividendos y participaciones',
                    '4004' => 'Otros ingresos',
                    '4005' => 'Ingresos por venta de activos fijos',
                    '4006' => 'Ingresos por arrendamientos',
                    '4007' => 'Ingresos por honorarios y comisiones',
                ],
            ],

            '1008' => [
                'name' => 'Saldo de cuentas por cobrar al 31 de diciembre',
                'description' => 'Saldo de las cuentas por cobrar a 31 de diciembre, por tercero.',
                'basis' => 'balance',
                'side' => 'debit',
                'concepts' => [
                    '1315' => 'Saldo de cuentas por cobrar al 31 de diciembre',
                ],
            ],

            '1009' => [
                'name' => 'Saldo de cuentas por pagar al 31 de diciembre',
                'description' => 'Saldo de los pasivos por pagar a 31 de diciembre, por tercero.',
                'basis' => 'balance',
                'side' => 'credit',
                'concepts' => [
                    '2201' => 'Saldo de cuentas por pagar a proveedores',
                    '2202' => 'Saldo de costos y gastos por pagar',
                    '2203' => 'Saldo de obligaciones financieras',
                    '2204' => 'Saldo de pasivos por impuestos, gravámenes y tasas',
                    '2205' => 'Otros pasivos por pagar',
                ],
            ],

            '1010' => [
                'name' => 'Información de socios, accionistas y comuneros',
                'description' => 'Aportes de los socios/accionistas. Se arma desde el Libro de Socios.',
                'basis' => 'partners',
                'side' => 'credit',
                'concepts' => [
                    '1110' => 'Aportes de socios o accionistas al 31 de diciembre',
                ],
            ],

            '1011' => [
                'name' => 'Información de las declaraciones tributarias',
                'description' => 'Valores de las declaraciones tributarias. Se diligencia manualmente.',
                'basis' => 'manual',
                'side' => 'debit',
                'concepts' => [
                    '8001' => 'Información de declaraciones tributarias (captura manual)',
                ],
            ],

            '1012' => [
                'name' => 'Datos informativos — saldos a 31 de diciembre',
                'description' => 'Saldos de inversiones, cuentas bancarias y otros activos a 31 de diciembre.',
                'basis' => 'balance',
                'side' => 'debit',
                'concepts' => [
                    '1115' => 'Saldo de acciones y aportes en sociedades',
                    '1116' => 'Saldo de cuentas corrientes y de ahorro (nacionales)',
                    '1117' => 'Saldo de cuentas e inversiones en el exterior',
                    '1118' => 'Saldo de inversiones (bonos, CDT, títulos y otros)',
                    '1119' => 'Saldo de créditos a favor y otros activos',
                ],
            ],
        ];
    }

    public static function formatCodes(): array
    {
        return array_keys(self::formats());
    }

    public static function format(string $code): ?array
    {
        return self::formats()[$code] ?? null;
    }

    /** Etiqueta corta "1001 — Pagos o abonos en cuenta". */
    public static function formatLabel(string $code): string
    {
        $f = self::format($code);

        return $f ? $code.' — '.$f['name'] : $code;
    }

    /** Opciones [code => label] para selects de formato. */
    public static function formatOptions(): array
    {
        $out = [];
        foreach (self::formats() as $code => $f) {
            $out[$code] = $code.' — '.$f['name'];
        }

        return $out;
    }

    public static function concepts(string $formatCode): array
    {
        return self::formats()[$formatCode]['concepts'] ?? [];
    }

    /** Opciones [code => "5004 — Servicios"] para selects de concepto. */
    public static function conceptOptions(string $formatCode): array
    {
        $out = [];
        foreach (self::concepts($formatCode) as $code => $name) {
            $out[$code] = $code.' — '.$name;
        }

        return $out;
    }

    public static function conceptName(string $formatCode, string $conceptCode): ?string
    {
        return self::formats()[$formatCode]['concepts'][$conceptCode] ?? null;
    }
}
