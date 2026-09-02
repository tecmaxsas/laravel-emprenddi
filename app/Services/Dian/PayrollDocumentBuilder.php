<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\PayrollSlip;
use RuntimeException;

/**
 * Arma el JSON de nomina electronica que espera apidian a partir de una
 * colilla ya liquidada.
 *
 * La estructura sale del ejemplo oficial de apidian: cabecera con los datos
 * del establecimiento, `period` con las fechas, `worker` con el trabajador,
 * `payment` con la forma de pago, y `accrued` / `deductions` con los totales.
 *
 * Los importes van como cadena con dos decimales porque asi los pide la API;
 * mandar un float hace que 859000.0 viaje como "859000" y la DIAN lo rechaza
 * por formato.
 */
class PayrollDocumentBuilder
{
    public function build(PayrollSlip $slip): array
    {
        $slip->loadMissing(['employee', 'contract', 'period', 'company']);

        $empleado = $slip->employee;
        $contrato = $slip->contract;
        $periodo = $slip->period;
        $empresa = $slip->company;

        // El municipio de la empresa vive en su configuracion DIAN, igual que
        // para las facturas: no esta en la tabla companies.
        $config = CompanyConfig::query()
            ->where('company_id', $slip->company_id)
            ->first();

        if (! $empleado) {
            throw new RuntimeException('La colilla no tiene empleado asociado.');
        }
        if (! $periodo) {
            throw new RuntimeException('La colilla no tiene periodo de nómina.');
        }
        if (! $slip->prefix || ! $slip->consecutive) {
            throw new RuntimeException('La colilla no tiene numeración asignada.');
        }

        return [
            'type_document_id' => PayrollCatalog::TYPE_DOCUMENT_NOMINA,

            'establishment_name' => $empresa?->name,
            'establishment_address' => $empresa?->address,
            'establishment_phone' => $empresa?->phone,
            'establishment_municipality' => $config?->dian_municipality_id,
            'establishment_email' => $empresa?->email,

            // Sin novedad: es un documento nuevo, no un ajuste de otro.
            'novelty' => [
                'novelty' => false,
                'uuidnov' => '',
            ],

            'period' => [
                'admision_date' => $empleado->hire_date?->toDateString(),
                'settlement_start_date' => $periodo->start_date?->toDateString(),
                'settlement_end_date' => $periodo->end_date?->toDateString(),
                'worked_time' => $this->tiempoLaborado($slip),
                'issue_date' => now()->toDateString(),
            ],

            'sendmail' => false,
            'sendmailtome' => false,

            'worker_code' => (string) $empleado->document_number,
            'prefix' => $slip->prefix,
            'consecutive' => (int) $slip->consecutive,
            'payroll_period_id' => PayrollCatalog::payrollPeriod($contrato?->payment_frequency),
            'notes' => 'Nómina '.$periodo->name,

            'worker' => [
                'type_worker_id' => $empleado->payroll_type_worker_id
                    ?? PayrollCatalog::TYPE_WORKER_DEPENDIENTE,
                'sub_type_worker_id' => $empleado->payroll_sub_type_worker_id
                    ?? PayrollCatalog::SUB_TYPE_WORKER_NO_APLICA,
                'payroll_type_document_identification_id' => PayrollCatalog::documentType($empleado->document_type),
                // Si el empleado no lo tiene, se usa el de la empresa: la DIAN lo
                // exige y dejarlo en null hace que rechace el documento.
                'municipality_id' => $empleado->dian_municipality_id ?? $config?->dian_municipality_id,
                'type_contract_id' => PayrollCatalog::contractType($contrato?->contract_type),
                'high_risk_pension' => (bool) $empleado->high_risk_pension,
                'identification_number' => (int) $empleado->document_number,
                'surname' => $empleado->last_name,
                'second_surname' => $empleado->second_last_name,
                'first_name' => $empleado->first_name,
                'middle_name' => $empleado->middle_name,
                'address' => $empleado->address,
                'integral_salarary' => PayrollCatalog::isIntegralSalary($contrato?->salary_type),
                'salary' => $this->monto($contrato?->salary ?? $slip->base_salary),
                'email' => $empleado->email,
            ],

            'payment' => [
                'payment_method_id' => PayrollCatalog::paymentMethod($empleado->payment_method),
                'bank_name' => $empleado->bank_name,
                'account_type' => strtoupper((string) $empleado->bank_account_type),
                'account_number' => $empleado->bank_account_number,
            ],

            'payment_dates' => [
                ['payment_date' => ($periodo->payment_date ?? $periodo->end_date)?->toDateString()],
            ],

            'accrued' => $this->devengados($slip),
            'deductions' => $this->deducciones($slip),
        ];
    }

    /**
     * Dias laborados en la empresa, desde el ingreso hasta el fin del periodo.
     */
    protected function tiempoLaborado(PayrollSlip $slip): string
    {
        $ingreso = $slip->employee?->hire_date;
        $fin = $slip->period?->end_date;

        if (! $ingreso || ! $fin) {
            return '0.00';
        }

        return number_format(max(0, $ingreso->diffInDays($fin)), 2, '.', '');
    }

    protected function devengados(PayrollSlip $slip): array
    {
        $devengados = [
            'worked_days' => (int) $slip->worked_days,
            'salary' => $this->monto($slip->salary_earned),
            'accrued_total' => $this->monto($slip->total_earnings),
        ];

        // El auxilio de transporte solo va si aplica: mandarlo en 0 hace que la
        // DIAN lo interprete como un devengado declarado en cero.
        if ((float) $slip->transport_allowance > 0) {
            $devengados['transportation_allowance'] = $this->monto($slip->transport_allowance);
        }

        return $devengados;
    }

    protected function deducciones(PayrollSlip $slip): array
    {
        return [
            'eps_type_law_deductions_id' => PayrollCatalog::DEDUCTION_SALUD,
            'eps_deduction' => $this->monto($slip->employee_health),
            'pension_type_law_deductions_id' => PayrollCatalog::DEDUCTION_PENSION,
            'pension_deduction' => $this->monto($slip->employee_pension),
            'deductions_total' => $this->monto($slip->total_deductions),
        ];
    }

    /** La API pide los importes como cadena con dos decimales. */
    protected function monto(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
