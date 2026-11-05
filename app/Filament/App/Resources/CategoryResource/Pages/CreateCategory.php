<?php

namespace App\Filament\App\Resources\CategoryResource\Pages;

use App\Filament\App\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * Al crear, volver al listado.
     *
     * Filament lleva por defecto a la ficha del registro recién creado, que es
     * lo útil cuando se crea de a uno. Pero estos dos se cargan en tanda —el
     * catálogo entero el primer día— y ahí la ficha es un desvío: hay que
     * devolverse para crear el siguiente, una vez por producto.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
