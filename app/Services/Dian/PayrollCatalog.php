<?php

namespace App\Services\Dian;

/**
 * Traduce lo que guardamos a los ids de catalogo que espera apidian en el
 * payload de nomina electronica.
 *
 * Todo el mapeo vive aqui a proposito. Los ids no son inventados: salen del
 * ejemplo oficial de apidian y de las tablas de la DIAN. Aun asi, apidian
 * numera sus catalogos internamente y no tiene por que coincidir con los
 * codigos de la DIAN, asi que CUALQUIER duda se resuelve consultando
 * POST /reports/master/database contra la API — no adivinando aqui.
 *
 * Anclas confirmadas en el ejemplo de apidian:
 *   type_worker_id: 1                        (dependiente)
 *   sub_type_worker_id: 1                    (no aplica)
 *   payroll_type_document_identification_id: 3  (cedula de ciudadania)
 *   type_contract_id: 1
 *   payment_method_id: 10
 *   eps_type_law_deductions_id: 1            (salud)
 *   pension_type_law_deductions_id: 5        (pension)
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
     * cheque.
     *
     * El 10 del ejemplo de apidian corresponde a consignacion, que es como se
     * paga la nomina casi siempre. Efectivo y cheque tienen su propio id en el
     * catalogo de medios de pago de la DIAN y hay que confirmarlos contra
     * /reports/master/database antes de usarlos en produccion.
     */
    public const PAYMENT_METHODS = [
        'deposito' => 10,
        'efectivo' => 10,
        'cheque' => 10,
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

    /** Nuestro employment_contracts.salary_type dice si es salario integral. */
    public static function isIntegralSalary(?string $tipoSalario): bool
    {
        return strtolower((string) $tipoSalario) === 'integral';
    }
}
