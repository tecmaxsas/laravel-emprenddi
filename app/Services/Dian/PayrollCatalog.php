<?php

namespace App\Services\Dian;

/**
 * Traduce lo que guardamos a los ids de catalogo que espera apidian en el
 * payload de nomina electronica.
 *
 * Todo el mapeo vive aqui a proposito. Los ids no son inventados: salen del
 * ejemplo oficial de apidian y de las tablas de la DIAN. Aun asi, apidian
 * numera sus catalogos internamente y no tiene por que coincidir con los
 * codigos de la DIAN, asi que CUALQUIER duda se resuelve con
 * `php artisan dian:payroll-catalogs`, que los trae de la instancia de la
 * empresa — no adivinando aqui.
 *
 * OJO con el ejemplo de Postman: manda payroll_period_id 4 para un periodo
 * del 1 al 31 de julio. El 4 es Quincenal, no Mensual — el ejemplo esta mal
 * etiquetado y copiarlo habria reportado mal la periodicidad.
 */
class PayrollCatalog
{
    /** Nomina individual. El de las notas de ajuste es otro. */
    public const TYPE_DOCUMENT_NOMINA = 9;

    /** Trabajador dependiente: el caso normal de una relacion laboral. */
    public const TYPE_WORKER_DEPENDIENTE = 1;

    public const SUB_TYPE_WORKER_NO_APLICA = 1;

    /** Deducciones de ley que van con id fijo en el payload. */
    public const DEDUCTION_SALUD = 1;

    public const DEDUCTION_PENSION = 5;

    /**
     * El trabajador de alto riesgo aporta a pension por un concepto distinto,
     * no por el 5 con una bandera aparte.
     */
    public const DEDUCTION_PENSION_ALTO_RIESGO = 7;

    /** Fondo de solidaridad pensional, a cargo del empleado. */
    public const DEDUCTION_FONDO_SOLIDARIDAD = 9;

    /**
     * Tipo de documento del trabajador.
     *
     * Nuestra tabla employees usa los mismos codigos que third_parties.
     */
    public const DOCUMENT_TYPES = [
        'rc' => 1,   // Registro civil
        'ti' => 2,   // Tarjeta de identidad
        'cc' => 3,   // Cedula de ciudadania
        'te' => 4,   // Tarjeta de extranjeria
        'ce' => 5,   // Cedula de extranjeria
        'nit' => 6,  // NIT
        'pasaporte' => 7,
        'die' => 8,  // Documento de identificacion extranjero
        'pep' => 9,  // Permiso especial de permanencia
        'nuip' => 11,
        'ppt' => 12, // Permiso de proteccion temporal
        // El RUT no existe en el catalogo de nomina: quien se identifica con
        // RUT lo hace con su NIT.
        'rut' => 6,
    ];

    /**
     * Tipo de contrato. Nuestro employment_contracts.contract_type.
     */
    public const CONTRACT_TYPES = [
        'fijo' => 1,
        'indefinido' => 2,
        'obra_labor' => 3,
        'aprendizaje' => 4,
        'practicas' => 5,
    ];

    /**
     * Periodicidad de pago. employment_contracts.payment_frequency solo maneja
     * mensual y quincenal; el resto queda por si se amplia.
     */
    public const PAYROLL_PERIODS = [
        'semanal' => 1,
        'decadal' => 2,
        'catorcenal' => 3,
        'quincenal' => 4,
        'mensual' => 5,
    ];

    /**
     * Medio de pago. Nuestro employees.payment_method: deposito, efectivo o
     * cheque. Ids verificados en la tabla payment_methods de apidian.
     *
     * El ejemplo de Postman manda 10 para un pago a cuenta de ahorros, pero el
     * 10 es EFECTIVO: reportaria una consignacion como pago en efectivo. La
     * consignacion bancaria es el 42.
     */
    public const PAYMENT_METHODS = [
        'deposito' => 42,  // Consignacion bancaria
        'efectivo' => 10,  // Efectivo
        'cheque' => 20,    // Cheque
    ];

    public static function documentType(?string $codigo): int
    {
        return self::DOCUMENT_TYPES[strtolower((string) $codigo)] ?? self::DOCUMENT_TYPES['cc'];
    }

    public static function contractType(?string $codigo): int
    {
        return self::CONTRACT_TYPES[strtolower((string) $codigo)] ?? self::CONTRACT_TYPES['indefinido'];
    }

    public static function payrollPeriod(?string $frecuencia): int
    {
        return self::PAYROLL_PERIODS[strtolower((string) $frecuencia)] ?? self::PAYROLL_PERIODS['mensual'];
    }

    public static function paymentMethod(?string $medio): int
    {
        return self::PAYMENT_METHODS[strtolower((string) $medio)] ?? self::PAYMENT_METHODS['deposito'];
    }

    /** Concepto de pension que le corresponde al trabajador. */
    public static function pensionDeduction(bool $altoRiesgo): int
    {
        return $altoRiesgo ? self::DEDUCTION_PENSION_ALTO_RIESGO : self::DEDUCTION_PENSION;
    }

    /** Nuestro employment_contracts.salary_type dice si es salario integral. */
    public static function isIntegralSalary(?string $tipoSalario): bool
    {
        return strtolower((string) $tipoSalario) === 'integral';
    }
}
