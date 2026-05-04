<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\SaleInvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSaleInvoices extends ListRecords
{
    protected static string $resource = SaleInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva factura de venta'),
        ];
    }
}
