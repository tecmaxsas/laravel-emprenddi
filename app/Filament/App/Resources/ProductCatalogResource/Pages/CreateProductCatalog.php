<?php

namespace App\Filament\App\Resources\ProductCatalogResource\Pages;

use App\Filament\App\Resources\ProductCatalogResource;
use App\Models\ProductCatalog;
use Filament\Resources\Pages\CreateRecord;

class CreateProductCatalog extends CreateRecord
{
    protected static string $resource = ProductCatalogResource::class;

    /**
     * El tema arranca completo aunque el usuario no abra la pestaña de diseño:
     * un catálogo a medio pintar se ve peor que uno con los colores de fábrica.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['theme'] = array_merge(ProductCatalog::DEFAULT_THEME, $data['theme'] ?? []);
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
