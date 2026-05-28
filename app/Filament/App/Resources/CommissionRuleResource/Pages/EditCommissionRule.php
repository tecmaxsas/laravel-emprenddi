<?php

namespace App\Filament\App\Resources\CommissionRuleResource\Pages;

use App\Filament\App\Resources\CommissionRuleResource;
use App\Models\CommissionRule;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCommissionRule extends EditRecord
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
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
