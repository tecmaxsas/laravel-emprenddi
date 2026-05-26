<?php

namespace App\Filament\App\Resources\PromotionResource\Pages;

use App\Filament\App\Resources\PromotionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    /**
     * El BelongsToCompany trait inyecta company_id en saving, no necesitamos
     * pisar nada aqui. Si no requires_code, limpiamos el codigo por si quedo
     * en el form.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['requires_code'])) {
            $data['code'] = null;
        }
        return $data;
    }
}
