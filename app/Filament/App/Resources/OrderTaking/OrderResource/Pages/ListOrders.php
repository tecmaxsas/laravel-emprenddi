<?php

namespace App\Filament\App\Resources\OrderTaking\OrderResource\Pages;

use App\Filament\App\Pages\OrderTaking\NewOrder;
use App\Filament\App\Resources\OrderTaking\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        // Usamos NewOrder::getUrl() (helper de Filament Page) en vez de
        // route('filament.app.pages.order-taking-new-order') — el nombre de
        // ruta auto-generado por Filament depende del namespace y no siempre
        // coincide con el patron esperado. getUrl() nunca falla.
        return [
            Actions\Action::make('new')
                ->label('Nuevo pedido')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(fn () => NewOrder::getUrl()),
        ];
    }
}
