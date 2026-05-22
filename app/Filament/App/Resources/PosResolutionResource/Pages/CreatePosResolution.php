<?php

namespace App\Filament\App\Resources\PosResolutionResource\Pages;

use App\Filament\App\Resources\PosResolutionResource;
use App\Models\Dian\Resolution;
use Filament\Resources\Pages\CreateRecord;

class CreatePosResolution extends CreateRecord
{
    protected static string $resource = PosResolutionResource::class;

    /**
     * Fuerza el tipo POS y el documento Factura — el resource solo
     * maneja resoluciones POS de factura.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['kind'] = Resolution::KIND_POS;
        $data['document_type_id'] = 1;
        $data['document_type_name'] = 'Factura POS';
        return $data;
    }
}
