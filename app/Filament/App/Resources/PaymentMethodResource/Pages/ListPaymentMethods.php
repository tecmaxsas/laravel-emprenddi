<?php

namespace App\Filament\App\Resources\PaymentMethodResource\Pages;

use App\Filament\App\Resources\PaymentMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo método de pago'),
        ];
    }
}
