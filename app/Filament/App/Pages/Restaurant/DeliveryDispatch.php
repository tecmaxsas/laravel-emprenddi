<?php

namespace App\Filament\App\Pages\Restaurant;

use App\Models\Location;
use App\Models\Restaurant\Driver;
use App\Models\Restaurant\Order;
use App\Services\Restaurant\RestaurantOrderEngine;
use App\Support\AccountantContext;
use App\Support\ModuleGate;
use App\Support\RestaurantSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Pantalla de despacho de domicilios: lista en tiempo real (polling)
 * de todos los pedidos delivery activos en la sede, con acciones rápidas
 * para asignar repartidor y mover entre estados (preparando → listo →
 * en camino → entregado).
 */
class DeliveryDispatch extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Despacho domicilios';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Despacho de domicilios';

    protected static ?string $slug = 'delivery-dispatch';

    protected static string $view = 'filament.app.pages.restaurant.delivery-dispatch';

    public ?int $locationId = null;
    public bool $autoRefresh = true;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! AccountantContext::ready()) return false;
        if (! RestaurantSettings::isEnabled('delivery')) return false;
        return (bool) Auth::user()?->can('restaurant.delivery.manage');
    }

    public function mount(): void
    {
        $companyId = auth()->user()?->company_id;
        $this->locationId = Location::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->where('is_main', true)
            ->value('id') ?? Location::query()
                ->where('company_id', $companyId)
                ->where('active', true)
                ->value('id');
    }

    public function getDeliveryOrdersProperty()
    {
        if (! $this->locationId) return collect();

        return Order::query()
            ->where('company_id', auth()->user()?->company_id)
            ->whereIn('status', [Order::STATUS_OPEN, Order::STATUS_IN_KITCHEN, Order::STATUS_SERVED, Order::STATUS_BILLING])
            ->where('location_id', $this->locationId)
            ->where('is_delivery', true)
            ->with(['items', 'server'])
            ->orderBy('opened_at')
            ->get();
    }

    public function getDriversProperty()
    {
        return Driver::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function assignDriver(int $orderId, ?int $driverId): void
    {
        $order = Order::query()->where('company_id', auth()->user()?->company_id)->find($orderId);
        if (! $order || ! $order->is_delivery) return;
        $driver = $driverId ? Driver::query()->where('company_id', auth()->user()?->company_id)->find($driverId) : null;
        try {
            app(RestaurantOrderEngine::class)->assignDeliveryDriver($order, $driver);
            Notification::make()
                ->title($driver ? "Driver asignado: {$driver->name}" : 'Driver removido')
                ->success()
                ->duration(1500)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    public function setStatus(int $orderId, string $status): void
    {
        $order = Order::query()->where('company_id', auth()->user()?->company_id)->find($orderId);
        if (! $order) return;
        try {
            app(RestaurantOrderEngine::class)->setDeliveryStatus($order, $status);
            Notification::make()
                ->title(Order::DELIVERY_STATUSES[$status] ?? $status)
                ->success()
                ->duration(1500)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }
}
