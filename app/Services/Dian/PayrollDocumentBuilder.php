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
        $this->exigirPeriodoCerrado($slip);

        if (! $slip->prefix || ! $slip->consecutive) {
            throw new RuntimeException('La colilla no tiene numeración asignada.');
        }

        return [
            'type_document_id' => PayrollCatalog::TYPE_DOCUMENT_NOMINA,

            // Sin `date` ni `time` al nivel superior. La fecha del documento
            // es period.issue_date; mandarlos aparte hacia que apidian
            // respondiera HTTP 500 sin detalle.

            // Ninguno puede ir en null: apidian los usa para armar el
            // documento y con un null lanza excepcion, que llega como
            // "Server Error" y no dice donde esta el problema.
            'establishment_name' => $empresa?->name ?: 'EMPRESA',
            'establishment_address' => $empresa?->address ?: 'SIN DIRECCION',
            'establishment_phone' => $empresa?->phone ?: '0000000',
            'establishment_municipality' => $config?->dian_municipality_id,
            'establishment_email' => $empresa?->email ?: 'sin@correo.com',

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
                'identification_number' => (string) $empleado->document_number,
                'surname' => $empleado->last_name,
                'second_surname' => $empleado->second_last_name,
                'first_name' => $empleado->first_name,
                'middle_name' => $empleado->middle_name,
                'address' => $empleado->address,
                'integral_salarary' => PayrollCatalog::isIntegralSalary($contrato?->salary_type),
                'salary' => $this->monto($contrato?->salary ?? $slip->base_salary),
                'email' => $empleado->email,
                // Repetido a proposito: el set de pruebas de la DIAN lo trae
                // en los dos sitios.
                'worker_code' => (string) $empleado->document_number,
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
     * Dias laborados EN EL PERIODO, no la antiguedad en la empresa.
     *
     * El primer ejemplo de Postman traia 785 con un periodo de un mes, que no
     * cuadra con ninguna de las dos lecturas; el del set de pruebas de la DIAN
     * manda 30 para enero, o sea los dias del periodo. Es tambien lo que dice
     * el estandar: TiempoLaborado es la cantidad de dias laborados.
     */
    /**
     * La fecha de emision no puede ser anterior al cierre del periodo: seria
     * un documento que certifica un pago que todavia no ocurrio. La DIAN lo
     * rechaza, y el rechazo llega dias despues por la via asincrona, cuando el
     * consecutivo ya se quemo. Se corta antes de enviar.
     *
     * Se llama tambien desde PayrollDianSender antes de reservar numeracion.
     */
    public function exigirPeriodoCerrado(PayrollSlip $slip): void
    {
        $periodo = $slip->period;

        if (! $periodo || ! $periodo->end_date || ! $periodo->end_date->isFuture()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'El periodo "%s" termina el %s y todavía no ha cerrado. La nómina electrónica se emite '
            .'cuando el periodo ya terminó: la DIAN rechaza un documento emitido antes de esa fecha.',
            $periodo->name,
            $periodo->end_date->format('d/m/Y'),
        ));
    }

    protected function tiempoLaborado(PayrollSlip $slip): int
    {
        return (int) $slip->worked_days;
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

    /**
     * Deducciones de ley.
     *
     * La DIAN valida que deductions_total sea la suma de lo desglosado. Este
     * payload solo desglosa salud y pension, asi que si la colilla trae otras
     * deducciones —fondo de solidaridad, retencion en la fuente, embargos— el
     * total no cuadraria y la DIAN rechaza con un mensaje que no dice cual
     * sobra. Mejor pararlo aqui y decirlo con nombre propio.
     */
    protected function deducciones(PayrollSlip $slip): array
    {
        $salud = round((float) $slip->employee_health, 2);
        $pension = round((float) $slip->employee_pension, 2);
        $total = round((float) $slip->total_deductions, 2);
        $sinDesglosar = round($total - $salud - $pension, 2);

        if (abs($sinDesglosar) > 0.01) {
            throw new RuntimeException(sprintf(
                'La colilla tiene $%s en deducciones que este documento no sabe desglosar (%s). '
                .'La DIAN exige que el total sea la suma de lo detallado, así que el envío se '
                .'rechazaría. Hace falta el payload extendido de apidian para reportarlas.',
                number_format($sinDesglosar, 2, ',', '.'),
                $this->conceptosNoDesglosados($slip) ?: 'concepto no identificado',
            ));
        }

        return [
            'eps_type_law_deductions_id' => PayrollCatalog::DEDUCTION_SALUD,
            'eps_deduction' => $this->monto($salud),
            'pension_type_law_deductions_id' => PayrollCatalog::pensionDeduction(
                (bool) $slip->employee?->high_risk_pension
            ),
            'pension_deduction' => $this->monto($pension),
            'deductions_total' => $this->monto($total),
        ];
    }

    /** Nombres de las deducciones que no son salud ni pension. */
    protected function conceptosNoDesglosados(PayrollSlip $slip): string
    {
        $slip->loadMissing('lines');

        return $slip->lines
            ->where('type', 'deduction')
            ->whereNotIn('concept_code', ['salud', 'pension'])
            ->pluck('concept_name')
            ->filter()
            ->implode(', ');
    }

    /** La API pide los importes como cadena con dos decimales. */
    protected function monto(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
