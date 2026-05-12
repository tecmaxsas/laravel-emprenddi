<?php

namespace App\Filament\App\Pages\Restaurant;

use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Printer;
use App\Services\Restaurant\RestaurantOrderEngine;
use App\Support\AccountantContext;
use App\Support\ModuleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * KDS — Kitchen Display System.
 *
 * Pantalla para cocina/barra. Muestra los items en preparación en
 * 3 columnas: Recibido (sent) | Preparando | Listo.
 *
 *  - Polling cada 5s para refrescar sin recargar manualmente.
 *  - Filtro por impresora destino: cada estación (cocina, barra) ve
 *    solo lo que le corresponde según la categoría del producto.
 *  - Click en card → avanza al siguiente estado.
 *  - Items "served" salen del KDS (terminados).
 */
class KitchenDisplay extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'Cocina (KDS)';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Pantalla de cocina';

    protected static ?string $slug = 'kitchen-display';

    protected static string $view = 'filament.app.pages.restaurant.kitchen-display';

    public ?int $printerId = null;
    public bool $autoRefresh = true;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! AccountantContext::ready()) return false;
        if (! \App\Support\RestaurantSettings::isEnabled('kds')) return false;
        return (bool) Auth::user()?->can('restaurant.kitchen');
    }

    public function getPrintersProperty()
    {
        return Printer::query()
            ->where('active', true)
            ->whereIn('purpose', ['kitchen', 'bar'])
            ->orderBy('name')
            ->get();
    }

    public function getReceivedItemsProperty()
    {
        return $this->itemsBaseQuery('sent')->get();
    }

    public function getPreparingItemsProperty()
    {
        return $this->itemsBaseQuery('preparing')->get();
    }

    public function getReadyItemsProperty()
    {
        return $this->itemsBaseQuery('ready')->get();
    }

    protected function itemsBaseQuery(string $kitchenStatus)
    {
        return OrderItem::query()
            ->where('kitchen_status', $kitchenStatus)
            ->when($this->printerId, fn ($q) => $q->where('printed_to_printer_id', $this->printerId))
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['open', 'in_kitchen', 'served', 'billing']))
            ->with(['order.table', 'order.zone', 'order.server'])
            ->orderBy('sent_to_kitchen_at')
            ->orderBy('id');
    }

    public function startPreparing(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        app(RestaurantOrderEngine::class)->markItemPreparing($item);
    }

    public function markReady(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        app(RestaurantOrderEngine::class)->markItemReady($item);
    }

    public function markServed(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        app(RestaurantOrderEngine::class)->markItemServed($item);

        Notification::make()
            ->title("✓ {$item->description}")
            ->body('Marcado como servido')
            ->success()
            ->duration(1200)
            ->send();
    }

    /**
     * Volver al estado anterior si se equivocó.
     */
    public function regressItem(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;

        $prev = match ($item->kitchen_status) {
            OrderItem::KS_PREPARING => OrderItem::KS_SENT,
            OrderItem::KS_READY => OrderItem::KS_PREPARING,
            default => null,
        };

        if ($prev) {
            $item->update(['kitchen_status' => $prev]);
        }
    }
}
