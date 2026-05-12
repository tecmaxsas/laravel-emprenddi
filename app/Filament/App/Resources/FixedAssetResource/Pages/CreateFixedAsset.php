<?php

namespace App\Filament\App\Resources\FixedAssetResource\Pages;

use App\Filament\App\Resources\FixedAssetResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFixedAsset extends CreateRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['status'] = 'active';
        $data['accumulated_depreciation'] = 0;
        return $data;
    }
}
