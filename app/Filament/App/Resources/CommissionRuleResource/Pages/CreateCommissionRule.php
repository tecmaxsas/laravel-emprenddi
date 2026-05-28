<?php

namespace App\Filament\App\Resources\CommissionRuleResource\Pages;

use App\Filament\App\Resources\CommissionRuleResource;
use App\Models\CommissionRule;
use Filament\Resources\Pages\CreateRecord;

class CreateCommissionRule extends CreateRecord
{
    protected static string $resource = CommissionRuleResource::class;

    /** Limpia category_id/product_id segun el scope para no dejar basura. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['scope'] ?? null) !== CommissionRule::SCOPE_CATEGORY) {
            $data['category_id'] = null;
        }
        if (($data['scope'] ?? null) !== CommissionRule::SCOPE_PRODUCT) {
            $data['product_id'] = null;
        }
        return $data;
    }
}
