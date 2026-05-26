<?php

namespace App\Filament\App\Resources\GiftCardResource\Pages;

use App\Filament\App\Resources\GiftCardResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGiftCards extends ListRecords
{
    protected static string $resource = GiftCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Emitir tarjeta')
                ->icon('heroicon-o-gift'),
        ];
    }
}
