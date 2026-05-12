<?php

namespace App\Filament\SuperAdmin\Resources\AccountantResource\Pages;

use App\Filament\SuperAdmin\Resources\AccountantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountant extends CreateRecord
{
    protected static string $resource = AccountantResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_accountant_portal'] = true;
        $data['is_super_admin'] = false;
        $data['company_id'] = null;  // contadores externos no pertenecen a una empresa
        return $data;
    }
}
