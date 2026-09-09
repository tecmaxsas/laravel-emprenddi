<?php

namespace App\Filament\App\Resources\ProductCatalogResource\Pages;

use App\Filament\App\Resources\ProductCatalogResource;
use App\Models\ProductCatalog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductCatalog extends EditRecord
{
    protected static string $resource = ProductCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ver')
                ->label('Ver catálogo')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (ProductCatalog $record) => $record->publicUrl(), shouldOpenInNewTab: true),

            Actions\DeleteAction::make(),
        ];
    }

    /** Un tema al que le falten claves rompería la vista pública. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['theme'] = array_merge(ProductCatalog::DEFAULT_THEME, $data['theme'] ?? []);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
