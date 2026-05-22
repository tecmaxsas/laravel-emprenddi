<?php

namespace App\Filament\App\Resources\PayrollSettlementResource\Pages;

use App\Filament\App\Resources\PayrollSettlementResource;
use App\Models\Employee;
use App\Models\PayrollParameter;
use App\Models\PayrollSettlement;
use App\Services\Payroll\PayrollSettlementCalculator;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreatePayrollSettlement extends CreateRecord
{
    protected static string $resource = PayrollSettlementResource::class;

    public function getTitle(): string
    {
        return 'Nueva liquidación de prestaciones';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $employee = Employee::find($data['employee_id']);
        $contract = $employee?->contracts()->first();
        if (! $contract) {
            throw ValidationException::withMessages([
                'employee_id' => 'El empleado no tiene un contrato registrado.',
            ]);
        }

        $year = Carbon::parse($data['period_end'])->year;
        $params = PayrollParameter::forYear($year);
        if (! $params) {
            throw ValidationException::withMessages([
                'period_end' => "No hay parámetros de nómina para el año {$year}. Configurá el SMMLV y el auxilio de transporte en Nómina → Parámetros.",
            ]);
        }

        $calc = app(PayrollSettlementCalculator::class)->calculate(
            $contract,
            $data['type'],
            (int) $data['worked_days'],
            (float) $params->smmlv,
            (float) $params->transport_allowance,
        );

        $data['company_id'] = Auth::user()->company_id;
        $data['employment_contract_id'] = $contract->id;
        $data['monthly_salary'] = $contract->salary;
        $data['base'] = $calc['base'];
        $data['amount_cesantias'] = $calc['cesantias'];
        $data['amount_interest'] = $calc['interest'];
        $data['amount_prima'] = $calc['prima'];
        $data['amount_vacaciones'] = $calc['vacaciones'];
        $data['amount'] = $calc['total'];
        $data['status'] = PayrollSettlement::STATUS_DRAFT;

        return $data;
    }
}
