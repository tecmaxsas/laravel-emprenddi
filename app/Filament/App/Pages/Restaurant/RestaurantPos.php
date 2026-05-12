<?php

namespace App\Filament\App\Pages\Restaurant;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\ServiceZone;
use App\Models\Restaurant\Table;
use App\Services\Restaurant\RestaurantOrderEngine;
use App\Support\AccountantContext;
use App\Support\ModuleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * POS Restaurante: mapa visual de mesas + panel lateral de orden activa.
 *
 *  Flujo:
 *   1. Mesa libre → click → "abrir cuenta" (crea Order, mesa → occupied)
 *   2. Mesa ocupada → click → ve la orden, puede agregar items
 *   3. Catálogo a la derecha filtrable por categoría
 *   4. Click producto → agrega línea con qty=1, sin modificadores
 *   5. Botón "Enviar a cocina" → marca items como sent y crea KOTs
 *   6. Cuando todo está servido → "Pedir cuenta" → status billing (Iter 21e)
 */
class RestaurantPos extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'POS Restaurante';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'POS Restaurante';

    protected static ?string $slug = 'restaurant-pos';

    protected static string $view = 'filament.app.pages.restaurant.pos';

    public ?int $locationId = null;
    public ?int $activeZoneId = null;
    public ?int $activeOrderId = null;
    public ?int $activeCategoryId = null;
    public string $productSearch = '';

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! AccountantContext::ready()) return false;
        return (bool) Auth::user()?->can('restaurant.use');
    }

    public function mount(): void
    {
        $this->locationId = Location::query()
            ->where('active', true)
            ->where('is_main', true)
            ->value('id') ?? Location::query()->where('active', true)->value('id');
    }

    // ============ DATA ============

    public function getZonesProperty()
    {
        return ServiceZone::query()
            ->where('active', true)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->orderBy('display_order')
            ->get();
    }

    public function getTablesProperty()
    {
        return Table::query()
            ->where('active', true)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->when($this->activeZoneId, fn ($q) => $q->where('zone_id', $this->activeZoneId))
            ->with('zone')
            ->orderBy('code')
            ->get();
    }

    public function getActiveOrderProperty(): ?Order
    {
        if (! $this->activeOrderId) return null;
        return Order::with(['items.product', 'table', 'zone', 'server'])->find($this->activeOrderId);
    }

    public function getCategoriesProperty()
    {
        return Category::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function getCatalogProperty()
    {
        return Product::query()
            ->where('active', true)
            ->where('is_sellable', true)
            ->when($this->activeCategoryId, fn ($q) => $q->where('category_id', $this->activeCategoryId))
            ->when($this->productSearch, function ($q) {
                $s = trim($this->productSearch);
                $q->where(function ($x) use ($s) {
                    $x->where('name', 'ilike', "%{$s}%")
                      ->orWhere('code', 'ilike', "%{$s}%")
                      ->orWhere('barcode', 'ilike', "%{$s}%");
                });
            })
            ->orderBy('name')
            ->limit(40)
            ->get();
    }

    // ============ ACTIONS ============

    public function selectTable(int $tableId): void
    {
        $table = Table::find($tableId);
        if (! $table) return;

        $order = $table->activeOrder();

        if ($order) {
            $this->activeOrderId = $order->id;
            return;
        }

        // Sin orden activa: abrir nueva
        try {
            $order = app(RestaurantOrderEngine::class)->openTableOrder($table);
            $this->activeOrderId = $order->id;
            Notification::make()
                ->title("Cuenta abierta en {$table->code}")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('No se pudo abrir la cuenta')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function closeOrderPanel(): void
    {
        $this->activeOrderId = null;
        $this->productSearch = '';
        $this->activeCategoryId = null;
    }

    public function addProduct(int $productId): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        $product = Product::find($productId);
        if (! $product) return;

        try {
            app(RestaurantOrderEngine::class)->addItem($order, $product, 1.0);
            Notification::make()
                ->title($product->name)
                ->body('Agregado a la cuenta')
                ->success()
                ->duration(1500)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al agregar')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function increaseQty(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        try {
            app(RestaurantOrderEngine::class)->updateItemQuantity($item, (float) $item->quantity + 1);
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    public function decreaseQty(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        $newQty = (float) $item->quantity - 1;
        try {
            app(RestaurantOrderEngine::class)->updateItemQuantity($item, $newQty);
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    public function cancelItem(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        app(RestaurantOrderEngine::class)->cancelItem($item, 'Cancelado por mesero');
        Notification::make()->title('Item cancelado')->warning()->send();
    }

    public function sendToKitchen(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        try {
            $tickets = app(RestaurantOrderEngine::class)->sendPendingToKitchen($order);
            $count = count($tickets);
            Notification::make()
                ->title("Comanda enviada")
                ->body($count > 0
                    ? "{$count} ticket(s) generado(s) por impresora."
                    : "Items marcados como enviados (sin impresora asignada).")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al enviar')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function cancelOrder(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        try {
            app(RestaurantOrderEngine::class)->cancel($order, 'Cancelado desde POS');
            Notification::make()->title('Orden cancelada')->warning()->send();
            $this->closeOrderPanel();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Cierra la cuenta y libera la mesa. Iter 21e: agregará cobro
     * con propina, división de cuenta y generación de SaleInvoice.
     * Por ahora solo libera la mesa para empezar de cero.
     */
    public function closeOrder(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        try {
            app(RestaurantOrderEngine::class)->close($order);
            Notification::make()
                ->title('Cuenta cerrada')
                ->body("Mesa {$order->table?->code} liberada y lista para nueva orden.")
                ->success()
                ->send();
            $this->closeOrderPanel();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }
}
