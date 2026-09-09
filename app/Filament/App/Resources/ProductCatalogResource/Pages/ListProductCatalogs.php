<?php

namespace App\Filament\App\Resources\ProductCatalogResource\Pages;

use App\Filament\App\Resources\ProductCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductCatalogs extends ListRecords
{
    protected static string $resource = ProductCatalogResource::class;

    public function getSubheading(): ?string
    {
        return 'Un enlace público con tus productos, para compartir por WhatsApp o redes. '
            .'Se actualiza solo: no hay nada que regenerar cuando cambias un precio o subes una foto.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Crear catálogo'),
        ];
    }
}
