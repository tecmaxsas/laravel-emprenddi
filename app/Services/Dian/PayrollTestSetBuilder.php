<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\Dian\PayrollTestDocument;
use Illuminate\Support\Carbon;

/**
 * Payloads del set de pruebas de nomina.
 *
 * Son documentos inventados: solo tienen que ser validos para la DIAN, no
 * corresponden a ningun empleado real. Por eso viven aparte de
 * PayrollDocumentBuilder, que arma la nomina de una colilla liquidada.
 */
class PayrollTestSetBuilder
{
    /**
     * Nomina individual del set.
     *
     * Cada documento tiene que ser DISTINTO: la DIAN no admite dos nominas del
     * mismo trabajador para el mismo periodo, y con los envios identicos solo
     * pasaba el primero. Se mueve el mes liquidado y la cedula, de modo que la
     * combinacion sigue siendo unica aunque los meses se repitan pasado el
     * primer año.
     *
     * @return array<string, mixed>
     */
    public function nomina(Company $empresa, CompanyConfig $config, string $prefijo, int $consecutivo, int $slot): array
    {
        $mes = $this->mesDelSlot($slot);
        $trabajador = $this->trabajadorDelSlot($slot);

        return array_merge($this->encabezado($empresa, $config), [
            'type_document_id' => PayrollCatalog::TYPE_DOCUMENT_NOMINA,

            'novelty' => [
                'novelty' => false,
                'uuidnov' => '',
            ],

            'period' => [
                'admision_date' => '2024-01-01',
                // Un mes ya cerrado: un documento emitido antes de que termine
                // el periodo que liquida certifica un pago que no ocurrio.
                'settlement_start_date' => $mes->copy()->startOfMonth()->toDateString(),
                'settlement_end_date' => $mes->copy()->endOfMonth()->toDateString(),
                'worked_time' => '30.00',
                'issue_date' => now()->toDateString(),
            ],

            // El prefijo TIENE que estar registrado en apidian: con uno
            // desconocido no encuentra la resolucion y responde Server Error.
            'prefix' => $prefijo,
            'consecutive' => $consecutivo,

            'worker_code' => (string) $trabajador,
            'payroll_period_id' => PayrollCatalog::PAYROLL_PERIODS['mensual'],
            'notes' => 'DOCUMENTO DE PRUEBA - SET DE HABILITACION DIAN NOMINA ELECTRONICA',

            'worker' => $this->trabajador($empresa, $config, $trabajador),
            'payment' => $this->pago(),
            'payment_dates' => [
                ['payment_date' => $mes->copy()->endOfMonth()->toDateString()],
            ],
            'accrued' => $this->devengados(),
            'deductions' => $this->deducciones(),
        ]);
    }

    /**
     * Nota de ajuste que REEMPLAZA a una nomina ya enviada (type_note 1).
     *
     * El bloque `predecessor` es lo que la ata a su nomina. La DIAN exige que
     * esa nomina ya le conste recibida: si no, responde NIAE191a "Documento a
     * Reemplazar no encuentra recibido en la Base de Datos". Por eso la nota
     * solo se manda cuando la nomina tiene CUNE, y si aun asi falla se
     * reintenta mas tarde.
     *
     * Reemplaza con el mismo contenido: el set de pruebas valida la estructura
     * del documento, no que los valores cambien.
     *
     * @return array<string, mixed>
     */
    public function nota(
        Company $empresa,
        CompanyConfig $config,
        PayrollTestDocument $nomina,
        string $prefijo,
        int $consecutivo,
    ): array {
        $mes = $this->mesDelSlot($nomina->slot);
        $trabajador = $this->trabajadorDelSlot($nomina->slot);

        return array_merge($this->encabezado($empresa, $config), [
            'type_document_id' => PayrollCatalog::TYPE_DOCUMENT_NOTA_AJUSTE,
            'type_note' => PayrollCatalog::TYPE_NOTE_REEMPLAZAR,

            'predecessor' => [
                // Solo el consecutivo y como entero, sin el prefijo: apidian
                // valida el tipo y responde "debe ser un número entero".
                'predecessor_number' => (int) $nomina->consecutive,
                'predecessor_cune' => $nomina->cune,
                'predecessor_issue_date' => $nomina->issue_date?->toDateString() ?? now()->toDateString(),
            ],

            'period' => [
                'admision_date' => '2024-01-01',
                'settlement_start_date' => $mes->copy()->startOfMonth()->toDateString(),
                'settlement_end_date' => $mes->copy()->endOfMonth()->toDateString(),
                'worked_time' => '30.00',
                'issue_date' => now()->toDateString(),
            ],

            'prefix' => $prefijo,
            'consecutive' => $consecutivo,

            'worker_code' => (string) $trabajador,
            'payroll_period_id' => PayrollCatalog::PAYROLL_PERIODS['mensual'],
            'notes' => 'NOTA DE AJUSTE DE PRUEBA - SET DE HABILITACION DIAN',

            'worker' => $this->trabajador($empresa, $config, $trabajador),
            'payment' => $this->pago(),
            'payment_dates' => [
                ['payment_date' => $mes->copy()->endOfMonth()->toDateString()],
            ],
            'accrued' => $this->devengados(),
            'deductions' => $this->deducciones(),
        ]);
    }

    /** Mes liquidado por el documento de un slot. Siempre uno ya cerrado. */
    public function mesDelSlot(int $slot): Carbon
    {
        return now()->subMonthsNoOverflow(1 + (max(1, $slot) - 1) % 11);
    }

    /** Cedula del trabajador de un slot. Distinta en cada documento. */
    public function trabajadorDelSlot(int $slot): int
    {
        return 1234567890 + max(1, $slot);
    }

    /**
     * Datos del establecimiento. Ninguno puede ir en null: apidian los usa
     * para armar el documento y con un null lanza una excepcion que llega como
     * "Server Error" sin decir donde.
     *
     * @return array<string, mixed>
     */
    protected function encabezado(Company $empresa, CompanyConfig $config): array
    {
        $correo = $empresa->email ?: 'sin@correo.com';

        return [
            // Sin `date` ni `time` al nivel superior: el payload que apidian
            // procesa no los trae y el que si los llevaba daba HTTP 500.
            'establishment_name' => $empresa->name ?: 'EMPRESA',
            'establishment_address' => $empresa->address ?: 'SIN DIRECCION',
            'establishment_phone' => $empresa->phone ?: '0000000',
            'establishment_municipality' => $config->dian_municipality_id,
            'establishment_email' => $correo,

            'head_note' => 'DOCUMENTO DE PRUEBA - SET DE HABILITACION DIAN NOMINA ELECTRONICA',
            'foot_note' => 'DOCUMENTO DE PRUEBA - SET DE HABILITACION DIAN NOMINA ELECTRONICA',

            'sendmail' => false,
            'sendmailtome' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function trabajador(Company $empresa, CompanyConfig $config, int $documento): array
    {
        return [
            'type_worker_id' => PayrollCatalog::TYPE_WORKER_DEPENDIENTE,
            'sub_type_worker_id' => PayrollCatalog::SUB_TYPE_WORKER_NO_APLICA,
            'payroll_type_document_identification_id' => PayrollCatalog::DOCUMENT_TYPES['cc'],
            'municipality_id' => $config->dian_municipality_id,
            'type_contract_id' => PayrollCatalog::CONTRACT_TYPES['indefinido'],
            'high_risk_pension' => false,
            'identification_number' => $documento,
            'surname' => 'PEREZ',
            'second_surname' => 'GARCIA',
            'first_name' => 'JUAN',
            'middle_name' => 'CARLOS',
            'address' => 'CALLE 123 NRO 45-67',
            'integral_salarary' => false,
            'salary' => '1500000.00',
            'email' => $empresa->email ?: 'sin@correo.com',
        ];
    }

    /** @return array<string, mixed> */
    protected function pago(): array
    {
        return [
            'payment_method_id' => PayrollCatalog::PAYMENT_METHODS['deposito'],
            'bank_name' => 'BANCO DE BOGOTA',
            'account_type' => 'AHORROS',
            'account_number' => '1234567890',
        ];
    }

    /** @return array<string, mixed> */
    protected function devengados(): array
    {
        return [
            'worked_days' => 30,
            'salary' => '1500000.00',
            'transportation_allowance' => '162000.00',
            'accrued_total' => '1662000.00',
        ];
    }

    /** @return array<string, mixed> */
    protected function deducciones(): array
    {
        return [
            'eps_type_law_deductions_id' => PayrollCatalog::DEDUCTION_SALUD,
            'eps_deduction' => '60000.00',
            'pension_type_law_deductions_id' => PayrollCatalog::DEDUCTION_PENSION,
            'pension_deduction' => '60000.00',
            'deductions_total' => '120000.00',
        ];
    }
}
