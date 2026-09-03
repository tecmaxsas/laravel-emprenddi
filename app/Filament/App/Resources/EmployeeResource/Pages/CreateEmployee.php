<?php

namespace App\Filament\App\Resources\EmployeeResource\Pages;

use App\Filament\App\Resources\EmployeeResource;
use App\Models\EmploymentContract;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /** @var array<string, mixed> */
    protected array $contrato = [];

    public function getTitle(): string
    {
        return 'Nuevo empleado';
    }

    /**
     * El contrato viaja en el mismo formulario pero es otra tabla, asi que se
     * aparta antes de crear el empleado y se guarda despues, cuando ya hay id.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->contrato = $data['contrato'] ?? [];
        unset($data['contrato']);

        return $data;
    }

    /**
     * Un empleado sin contrato no lo toma la liquidacion, asi que se crea de
     * una vez con el suyo. Los siguientes se administran en la pestaña de
     * contratos, que conserva el historial.
     */
    protected function afterCreate(): void
    {
        if ($this->contrato === []) {
            return;
        }

        EmploymentContract::create([
            ...$this->contrato,
            'company_id' => $this->record->company_id,
            'employee_id' => $this->record->id,
            'status' => EmploymentContract::STATUS_ACTIVE,
        ]);
    }
}
