<?php

namespace App\Services\Accounting;

use App\Models\Account;

/**
 * Catálogo del Plan Único de Cuentas (PUC) Colombia — Decreto 2650/1993.
 *
 * Devuelve un set curado de las cuentas más usadas (clases, grupos, cuentas,
 * subcuentas más comunes). Las empresas pueden añadir más desde el panel.
 *
 * Formato: [['code' => '...', 'name' => '...', 'requires_third_party' => bool?], ...]
 */
class PucCatalog
{
    private const REQUIRE_TP = true;

    public static function standard(): array
    {
        return self::flatten(self::definitions());
    }

    private static function flatten(array $definitions): array
    {
        $out = [];
        foreach ($definitions as $def) {
            $code = (string) $def[0];
            $out[] = [
                'code' => $code,
                'name' => $def[1],
                'requires_third_party' => (bool) ($def[2] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Definiciones [código, nombre, requires_third_party?].
     * El 3er parámetro indica si exige tercero (cliente/proveedor) al hacer movimientos.
     */
    private static function definitions(): array
    {
        return [
            // ============================================================
            // CLASE 1 — ACTIVO
            // ============================================================
            ['1', 'ACTIVO'],
            ['11', 'DISPONIBLE'],
            ['1105', 'CAJA'],
            ['110505', 'Caja general'],
            ['110510', 'Cajas menores'],
            ['110515', 'Caja moneda extranjera'],
            ['1110', 'BANCOS'],
            ['111005', 'Moneda nacional'],
            ['111010', 'Moneda extranjera'],
            ['1115', 'REMESAS EN TRÁNSITO'],
            ['111505', 'Moneda nacional'],
            ['1120', 'CUENTAS DE AHORRO'],
            ['112005', 'Bancos'],
            ['112010', 'Corporaciones de ahorro y vivienda'],
            ['1125', 'FONDOS'],
            ['112505', 'Rotatorios moneda nacional'],

            ['12', 'INVERSIONES'],
            ['1205', 'Acciones'],
            ['1215', 'Bonos'],
            ['1225', 'Certificados (CDT)'],
            ['1235', 'Aceptaciones bancarias y financieras'],
            ['1245', 'Derechos fiduciarios'],
            ['1280', 'Provisiones (CR)'],

            ['13', 'DEUDORES'],
            ['1305', 'CLIENTES', self::REQUIRE_TP],
            ['130505', 'Clientes nacionales', self::REQUIRE_TP],
            ['130510', 'Clientes del exterior', self::REQUIRE_TP],
            ['1310', 'Cuentas corrientes comerciales', self::REQUIRE_TP],
            ['1320', 'Cuentas por cobrar a vinculados económicos', self::REQUIRE_TP],
            ['1325', 'Cuentas por cobrar a socios y accionistas', self::REQUIRE_TP],
            ['1330', 'ANTICIPOS Y AVANCES', self::REQUIRE_TP],
            ['133005', 'Anticipos a proveedores', self::REQUIRE_TP],
            ['133010', 'Anticipos a contratistas', self::REQUIRE_TP],
            ['133015', 'Anticipos a trabajadores', self::REQUIRE_TP],
            ['1335', 'Depósitos'],
            ['1345', 'Ingresos por cobrar'],
            ['1355', 'ANTICIPO DE IMPUESTOS Y CONTRIBUCIONES'],
            ['135505', 'Anticipo de impuesto de renta'],
            ['135515', 'Retención en la fuente'],
            ['135517', 'Impuesto a las ventas retenido'],
            ['135518', 'Impuesto de industria y comercio retenido'],
            ['1360', 'Reclamaciones'],
            ['1365', 'Cuentas por cobrar a trabajadores', self::REQUIRE_TP],
            ['1380', 'Deudores varios', self::REQUIRE_TP],
            ['1399', 'Provisiones (CR)'],

            ['14', 'INVENTARIOS'],
            ['1405', 'Materias primas'],
            ['1410', 'Productos en proceso'],
            ['1430', 'Productos terminados'],
            ['1435', 'MERCANCÍAS NO FABRICADAS POR LA EMPRESA'],
            ['143505', 'Inventario general'],
            ['1455', 'Materiales, repuestos y accesorios'],
            ['1460', 'Envases y empaques'],
            ['1465', 'Inventarios en tránsito'],
            ['1499', 'Provisiones (CR)'],

            ['15', 'PROPIEDADES, PLANTA Y EQUIPO'],
            ['1504', 'Terrenos'],
            ['1508', 'Construcciones en curso'],
            ['1516', 'Construcciones y edificaciones'],
            ['1520', 'Maquinaria y equipo'],
            ['1524', 'EQUIPO DE OFICINA'],
            ['152405', 'Muebles y enseres'],
            ['152410', 'Equipos'],
            ['1528', 'EQUIPO DE COMPUTACIÓN Y COMUNICACIÓN'],
            ['152805', 'Equipos de procesamiento de datos'],
            ['152810', 'Equipos de telecomunicaciones'],
            ['1532', 'Equipo médico-científico'],
            ['1536', 'Equipo de hoteles y restaurantes'],
            ['1540', 'FLOTA Y EQUIPO DE TRANSPORTE'],
            ['154005', 'Autos, camionetas, camperos'],
            ['154010', 'Camiones, volquetas y furgones'],
            ['1592', 'Depreciación acumulada (CR)'],
            ['1597', 'Provisiones (CR)'],

            ['16', 'INTANGIBLES'],
            ['1605', 'Crédito mercantil'],
            ['1610', 'Marcas'],
            ['1615', 'Patentes'],
            ['1620', 'Concesiones y franquicias'],
            ['1625', 'Derechos'],
            ['1635', 'Licencias'],
            ['1698', 'Depreciación y/o amortización acumulada (CR)'],
            ['1699', 'Provisiones (CR)'],

            ['17', 'DIFERIDOS'],
            ['1705', 'GASTOS PAGADOS POR ANTICIPADO'],
            ['170505', 'Intereses'],
            ['170510', 'Honorarios'],
            ['170520', 'Seguros y fianzas'],
            ['170525', 'Arrendamientos'],
            ['1710', 'CARGOS DIFERIDOS'],
            ['171010', 'Organización y preoperativos'],
            ['171015', 'Remodelaciones'],
            ['171020', 'Estudios, investigaciones y proyectos'],

            // ============================================================
            // CLASE 2 — PASIVO
            // ============================================================
            ['2', 'PASIVO'],
            ['21', 'OBLIGACIONES FINANCIERAS'],
            ['2105', 'BANCOS NACIONALES', self::REQUIRE_TP],
            ['210505', 'Sobregiros'],
            ['210510', 'Pagarés'],
            ['210515', 'Cartas de crédito'],
            ['2110', 'Bancos del exterior', self::REQUIRE_TP],
            ['2115', 'Corporaciones financieras', self::REQUIRE_TP],
            ['2120', 'Compañías de financiamiento comercial', self::REQUIRE_TP],

            ['22', 'PROVEEDORES'],
            ['2205', 'NACIONALES', self::REQUIRE_TP],
            ['220505', 'Proveedores nacionales', self::REQUIRE_TP],
            ['2210', 'Del exterior', self::REQUIRE_TP],

            ['23', 'CUENTAS POR PAGAR'],
            ['2305', 'Cuentas corrientes comerciales', self::REQUIRE_TP],
            ['2320', 'A contratistas', self::REQUIRE_TP],
            ['2335', 'COSTOS Y GASTOS POR PAGAR', self::REQUIRE_TP],
            ['233505', 'Gastos financieros'],
            ['233515', 'Libros, suscripciones, periódicos y revistas'],
            ['233520', 'Comisiones'],
            ['233525', 'Honorarios'],
            ['233530', 'Servicios técnicos'],
            ['233535', 'Servicios de mantenimiento'],
            ['233540', 'Arrendamientos'],
            ['233550', 'Servicios públicos'],
            ['233595', 'Otros'],
            ['2355', 'Deudas con accionistas o socios', self::REQUIRE_TP],
            ['2360', 'Dividendos o participaciones por pagar', self::REQUIRE_TP],
            ['2365', 'RETENCIÓN EN LA FUENTE'],
            ['236505', 'Salarios y pagos laborales'],
            ['236515', 'Honorarios'],
            ['236520', 'Comisiones'],
            ['236525', 'Servicios'],
            ['236530', 'Arrendamientos'],
            ['236535', 'Rendimientos financieros'],
            ['236540', 'Compras'],
            ['236570', 'Otras retenciones'],
            ['2367', 'Impuesto a las ventas retenido'],
            ['2368', 'Impuesto de industria y comercio retenido'],
            ['2370', 'RETENCIONES Y APORTES DE NÓMINA'],
            ['237005', 'Aportes a EPS'],
            ['237006', 'Aportes a ARL'],
            ['237010', 'Aportes al ICBF, SENA y cajas de compensación'],
            ['237025', 'Embargos judiciales'],
            ['237030', 'Libranzas'],
            ['2380', 'Acreedores varios', self::REQUIRE_TP],

            ['24', 'IMPUESTOS, GRAVÁMENES Y TASAS'],
            ['2404', 'DE RENTA Y COMPLEMENTARIOS'],
            ['240405', 'Vigencia fiscal corriente'],
            ['2408', 'IMPUESTO SOBRE LAS VENTAS POR PAGAR'],
            ['240805', 'IVA generado'],
            ['240810', 'IVA descontable'],
            ['2412', 'De industria y comercio'],
            ['2495', 'Otros'],

            ['25', 'OBLIGACIONES LABORALES'],
            ['2505', 'Salarios por pagar', self::REQUIRE_TP],
            ['2510', 'Cesantías consolidadas'],
            ['2515', 'Intereses sobre cesantías'],
            ['2520', 'Prima de servicios'],
            ['2525', 'Vacaciones consolidadas'],
            ['2530', 'Prestaciones extralegales'],

            ['26', 'PASIVOS ESTIMADOS Y PROVISIONES'],
            ['2605', 'Para costos y gastos'],
            ['2610', 'Para obligaciones laborales'],
            ['2615', 'Para obligaciones fiscales'],
            ['2635', 'Para contingencias'],
            ['2695', 'Provisiones diversas'],

            ['27', 'DIFERIDOS'],
            ['2705', 'INGRESOS RECIBIDOS POR ANTICIPADO'],
            ['270505', 'Anticipos sobre contratos'],
            ['270510', 'Comisiones'],
            ['270515', 'Arrendamientos'],

            ['28', 'OTROS PASIVOS'],
            ['2805', 'ANTICIPOS Y AVANCES RECIBIDOS', self::REQUIRE_TP],
            ['280505', 'Anticipos de clientes', self::REQUIRE_TP],
            ['2810', 'Depósitos recibidos'],
            ['2815', 'Ingresos recibidos para terceros', self::REQUIRE_TP],
            ['2895', 'Diversos'],

            // ============================================================
            // CLASE 3 — PATRIMONIO
            // ============================================================
            ['3', 'PATRIMONIO'],
            ['31', 'CAPITAL SOCIAL'],
            ['3105', 'CAPITAL SUSCRITO Y PAGADO'],
            ['310505', 'Capital autorizado'],
            ['310510', 'Capital por suscribir (DB)'],
            ['310515', 'Capital suscrito por cobrar (DB)'],
            ['3115', 'Aportes sociales'],
            ['3130', 'Capital de personas naturales'],

            ['32', 'SUPERÁVIT DE CAPITAL'],
            ['3205', 'Prima en colocación de acciones'],
            ['3210', 'Donaciones'],

            ['33', 'RESERVAS'],
            ['3305', 'RESERVAS OBLIGATORIAS'],
            ['330505', 'Reserva legal'],
            ['3310', 'Reservas estatutarias'],
            ['3315', 'Reservas ocasionales'],

            ['34', 'REVALORIZACIÓN DEL PATRIMONIO'],
            ['3405', 'Ajustes por inflación'],

            ['36', 'RESULTADOS DEL EJERCICIO'],
            ['3605', 'Utilidad del ejercicio'],
            ['3610', 'Pérdida del ejercicio (DB)'],

            ['37', 'RESULTADOS DE EJERCICIOS ANTERIORES'],
            ['3705', 'Utilidades acumuladas'],
            ['3710', 'Pérdidas acumuladas (DB)'],

            ['38', 'SUPERÁVIT POR VALORIZACIONES'],
            ['3810', 'De propiedades planta y equipo'],

            // ============================================================
            // CLASE 4 — INGRESOS
            // ============================================================
            ['4', 'INGRESOS'],
            ['41', 'OPERACIONALES'],
            ['4135', 'COMERCIO AL POR MAYOR Y AL POR MENOR'],
            ['413524', 'Comercio de productos en general'],
            ['413535', 'Repuestos y accesorios'],
            ['413536', 'Productos farmacéuticos'],
            ['413550', 'Restaurantes y similares'],
            ['4140', 'Hoteles y restaurantes'],
            ['4145', 'Transporte, almacenamiento y comunicaciones'],
            ['4155', 'Actividades inmobiliarias'],
            ['4160', 'Enseñanza'],
            ['4165', 'Servicios sociales y de salud'],
            ['4170', 'Otras actividades de servicios'],
            ['4175', 'Devoluciones en ventas (DB)'],

            ['42', 'NO OPERACIONALES'],
            ['4210', 'FINANCIEROS'],
            ['421005', 'Intereses'],
            ['421020', 'Reajuste monetario - UVR'],
            ['421040', 'Descuentos comerciales condicionados'],
            ['4215', 'Dividendos y participaciones'],
            ['4220', 'Arrendamientos'],
            ['4225', 'Comisiones'],
            ['4230', 'Honorarios'],
            ['4235', 'Servicios'],
            ['4245', 'Utilidad en venta de propiedades planta y equipo'],
            ['4255', 'Recuperaciones'],
            ['4295', 'Diversos'],

            // ============================================================
            // CLASE 5 — GASTOS
            // ============================================================
            ['5', 'GASTOS'],
            ['51', 'OPERACIONALES DE ADMINISTRACIÓN'],
            ['5105', 'GASTOS DE PERSONAL'],
            ['510506', 'Sueldos'],
            ['510512', 'Jornales'],
            ['510515', 'Horas extras y recargos'],
            ['510518', 'Comisiones'],
            ['510521', 'Viáticos'],
            ['510524', 'Incapacidades'],
            ['510527', 'Auxilio de transporte'],
            ['510530', 'Cesantías'],
            ['510533', 'Intereses sobre cesantías'],
            ['510536', 'Prima de servicios'],
            ['510539', 'Vacaciones'],
            ['510568', 'Aportes a EPS'],
            ['510569', 'Aportes a ARL'],
            ['510570', 'Aportes al ICBF, SENA y cajas'],
            ['510572', 'Dotación y suministro a trabajadores'],
            ['5110', 'Honorarios', self::REQUIRE_TP],
            ['5115', 'IMPUESTOS'],
            ['511505', 'Industria y comercio'],
            ['511515', 'Predial'],
            ['511520', 'Vehículos'],
            ['511595', 'Otros'],
            ['5120', 'Arrendamientos'],
            ['5125', 'Contribuciones y afiliaciones'],
            ['5130', 'Seguros'],
            ['5135', 'SERVICIOS'],
            ['513505', 'Aseo y vigilancia'],
            ['513510', 'Asistencia técnica'],
            ['513515', 'Procesamiento electrónico de datos'],
            ['513525', 'Acueducto y alcantarillado'],
            ['513530', 'Energía eléctrica'],
            ['513535', 'Teléfono'],
            ['513540', 'Correo, portes y telegramas'],
            ['513555', 'Transporte, fletes y acarreos'],
            ['513595', 'Otros'],
            ['5140', 'Gastos legales'],
            ['5145', 'Mantenimiento y reparaciones'],
            ['5150', 'Adecuación e instalación'],
            ['5155', 'Gastos de viaje'],
            ['5160', 'Depreciaciones'],
            ['5165', 'Amortizaciones'],
            ['5195', 'Diversos'],
            ['5199', 'Provisiones'],

            ['52', 'OPERACIONALES DE VENTAS'],
            ['5205', 'Gastos de personal'],
            ['5210', 'Honorarios'],
            ['5215', 'Impuestos'],
            ['5220', 'Arrendamientos'],
            ['5230', 'Seguros'],
            ['5235', 'Servicios'],
            ['5245', 'Mantenimiento y reparaciones'],
            ['5260', 'Depreciaciones'],
            ['5265', 'Amortizaciones'],
            ['5295', 'Diversos'],

            ['53', 'NO OPERACIONALES'],
            ['5305', 'FINANCIEROS'],
            ['530505', 'Gastos bancarios'],
            ['530515', 'Comisiones'],
            ['530520', 'Intereses'],
            ['530525', 'Diferencia en cambio'],
            ['5310', 'Pérdida en venta y retiro de bienes'],
            ['5315', 'Gastos extraordinarios'],
            ['5395', 'Gastos diversos'],

            ['54', 'IMPUESTO DE RENTA Y COMPLEMENTARIOS'],
            ['5405', 'Impuesto de renta y complementarios'],

            ['59', 'GANANCIAS Y PÉRDIDAS'],
            ['5905', 'Ganancias y pérdidas'],

            // ============================================================
            // CLASE 6 — COSTOS DE VENTAS
            // ============================================================
            ['6', 'COSTOS DE VENTAS'],
            ['61', 'COSTO DE VENTAS Y DE PRESTACIÓN DE SERVICIOS'],
            ['6135', 'COMERCIO AL POR MAYOR Y AL POR MENOR'],
            ['613524', 'Comercio de productos en general'],
            ['613535', 'Repuestos y accesorios'],
            ['613536', 'Productos farmacéuticos'],
            ['613550', 'Restaurantes'],
            ['6140', 'Hoteles y restaurantes'],
            ['6145', 'Transporte'],
            ['6155', 'Actividades inmobiliarias'],
            ['6160', 'Enseñanza'],
            ['6165', 'Servicios sociales y de salud'],
            ['6170', 'Otras actividades de servicios'],

            ['62', 'COMPRAS'],
            ['6205', 'De mercancías'],

            // ============================================================
            // CLASE 7 — COSTOS DE PRODUCCIÓN
            // ============================================================
            ['7', 'COSTOS DE PRODUCCIÓN O DE OPERACIÓN'],
            ['71', 'MATERIA PRIMA'],
            ['72', 'MANO DE OBRA DIRECTA'],
            ['73', 'COSTOS INDIRECTOS'],
            ['74', 'CONTRATOS DE SERVICIOS'],

            // ============================================================
            // CLASE 8 — CUENTAS DE ORDEN DEUDORAS
            // ============================================================
            ['8', 'CUENTAS DE ORDEN DEUDORAS'],
            ['81', 'DERECHOS CONTINGENTES'],
            ['83', 'DEUDORAS DE CONTROL'],
            ['84', 'DEUDORAS POR CONTRA (CR)'],

            // ============================================================
            // CLASE 9 — CUENTAS DE ORDEN ACREEDORAS
            // ============================================================
            ['9', 'CUENTAS DE ORDEN ACREEDORAS'],
            ['91', 'RESPONSABILIDADES CONTINGENTES'],
            ['93', 'ACREEDORAS DE CONTROL'],
            ['94', 'ACREEDORAS POR CONTRA (DB)'],
        ];
    }
}
