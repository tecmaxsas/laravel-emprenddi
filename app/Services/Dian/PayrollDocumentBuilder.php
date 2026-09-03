<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipLine;
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

    /**
     * Dias laborados EN EL PERIODO, no la antiguedad en la empresa.
     *
     * El primer ejemplo de Postman traia 785 con un periodo de un mes, que no
     * cuadra con ninguna de las dos lecturas; el del set de pruebas de la DIAN
     * manda 30 para enero, o sea los dias del periodo. Es tambien lo que dice
     * el estandar: TiempoLaborado es la cantidad de dias laborados.
     */
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
     * Deducciones del trabajador.
     *
     * La DIAN valida que deductions_total sea EXACTAMENTE la suma de lo
     * desglosado, asi que cada peso que descuenta la colilla tiene que caer en
     * algun concepto. Se reportan por separado salud, pension, fondo de
     * solidaridad y retencion en la fuente, que son los que la DIAN nombra; lo
     * demas —libranzas, prestamos, embargos— va a "otras deducciones", que es
     * la bolsa que el estandar deja para eso.
     *
     * Si aun asi queda un descuadre se para el envio: llegar a la DIAN con el
     * total mal da un rechazo dias despues, con el consecutivo ya gastado.
     *
     * @return array<string, mixed>
     */
    protected function deducciones(PayrollSlip $slip): array
    {
        $salud = round((float) $slip->employee_health, 2);
        $pension = round((float) $slip->employee_pension, 2);
        $fondoSolidaridad = round((float) $slip->solidarity_fund, 2);
        $retencion = round($this->sumaDeConceptos($slip, ['retencion_fuente']), 2);

        $otras = round($this->sumaDeConceptos(
            $slip,
            ['salud', 'pension', 'fsp', 'retencion_fuente'],
            excluir: true,
        ), 2);

        $total = round((float) $slip->total_deductions, 2);
        $desglosado = round($salud + $pension + $fondoSolidaridad + $retencion + $otras, 2);
        $descuadre = round($total - $desglosado, 2);

        if (abs($descuadre) > 0.01) {
            throw new RuntimeException(sprintf(
                'Las deducciones de la colilla no cuadran: el total es $%s pero los conceptos suman $%s '
                .'(diferencia de $%s). La DIAN exige que el total sea la suma de lo detallado, así que '
                .'rechazaría el documento. Revisa la liquidación del período antes de enviar.',
                number_format($total, 2, ',', '.'),
                number_format($desglosado, 2, ',', '.'),
                number_format($descuadre, 2, ',', '.'),
            ));
        }

        $deducciones = [
            'eps_type_law_deductions_id' => PayrollCatalog::DEDUCTION_SALUD,
            'eps_deduction' => $this->monto($salud),
            'pension_type_law_deductions_id' => PayrollCatalog::pensionDeduction(
                (bool) $slip->employee?->high_risk_pension
            ),
            'pension_deduction' => $this->monto($pension),
        ];

        // Los opcionales solo van si tienen valor: mandarlos en cero hace que
        // la DIAN los lea como un concepto declarado en cero, no como ausente.
        if ($fondoSolidaridad > 0) {
            $deducciones['fondossp_type_law_deductions_id'] = PayrollCatalog::DEDUCTION_FONDO_SOLIDARIDAD;
            $deducciones['fondosp_deduction_SP'] = $this->monto($fondoSolidaridad);
        }

        if ($retencion > 0) {
            $deducciones['withholding_at_source'] = $this->monto($retencion);
        }

        if ($otras > 0) {
            $deducciones['other_deductions'] = [
                ['other_deduction' => $this->monto($otras)],
            ];
        }

        $deducciones['deductions_total'] = $this->monto($total);

        return $deducciones;
    }

    /**
     * Suma las lineas de deduccion de la colilla.
     *
     * @param  list<string>  $codigos
     * @param  bool  $excluir  true suma todo MENOS esos codigos.
     */
    protected function sumaDeConceptos(PayrollSlip $slip, array $codigos, bool $excluir = false): float
    {
        $slip->loadMissing('lines');

        return (float) $slip->lines
            ->where('type', PayrollSlipLine::TYPE_DEDUCTION)
            ->filter(fn ($linea) => $excluir
                ? ! in_array($linea->concept_code, $codigos, true)
                : in_array($linea->concept_code, $codigos, true))
            ->sum('amount');
    }

    /** La API pide los importes como cadena con dos decimales. */
    protected function monto(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
