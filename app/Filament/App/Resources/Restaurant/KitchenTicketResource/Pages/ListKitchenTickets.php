<?php

namespace App\Filament\App\Resources\Restaurant\KitchenTicketResource\Pages;

use App\Filament\App\Resources\Restaurant\KitchenTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListKitchenTickets extends ListRecords
{
    protected static string $resource = KitchenTicketResource::class;

    /** No hay acción de crear: las comandas nacen al enviar a cocina desde el POS. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
