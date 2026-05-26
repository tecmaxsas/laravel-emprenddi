<?php

namespace App\Filament\App\Resources\PromotionResource\Pages;

use App\Filament\App\Resources\PromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Si quitan requires_code, limpiamos el codigo para no dejar basura. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['requires_code'])) {
            $data['code'] = null;
        }
        return $data;
    }
}
