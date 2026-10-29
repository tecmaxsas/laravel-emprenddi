<?php

namespace App\Filament\App\Resources\Hotel\StayResource\Pages;

use App\Filament\App\Resources\Hotel\StayResource;
use App\Models\Hotel\Stay;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStay extends ViewRecord
{
    protected static string $resource = StayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('imprimirFolio')
                ->label('Imprimir cuenta')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (Stay $record) => route('hotel.folio.print', $record))
                ->openUrlInNewTab(),
        ];
    }
}
