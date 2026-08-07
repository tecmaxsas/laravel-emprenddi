<?php

namespace App\Filament\App\Resources\OrderTaking\OrderResource\Pages;

use App\Filament\App\Resources\OrderTaking\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('new')
                ->label('Nuevo pedido')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(fn () => route('filament.app.pages.order-taking-new-order')),
        ];
    }
}
