<?php

namespace App\Filament\App\Pages\OrderTaking;

use App\Filament\App\Resources\OrderTaking\OrderResource;
use App\Models\Location;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\PriceList;
use App\Models\OrderTaking\PriceListItem;
use App\Models\ThirdParty;
use App\Services\OrderTaking\OrderEngine;
use App\Support\ModuleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Toma de pedidos estilo POS: escoges cliente, se carga su lista de precios,
 * buscas productos y los agregas con cantidades. Al guardar se crea el pedido
 * en status draft con sus lineas listas para confirmar/despachar.
 */
class NewOrder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';
    protected static ?string $navigationLabel = 'Nuevo pedido';
    protected static ?string $navigationGroup = 'Toma pedidos';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'order-taking/new';
    protected static ?string $title = 'Nuevo pedido';

    protected static string $view = 'filament.app.pages.order-taking.new-order';

    public ?int $customerId = null;
    public ?int $priceListId = null;
    public ?int $locationId = null;
    public ?string $orderDate = null;
    public ?string $deliveryDateExpected = null;
    public string $notes = '';
    public string $productSearch = '';

    /** cada item: ['product_id', 'code', 'name', 'quantity', 'price_before_tax', 'tax_amount', 'price_at_public'] */
    public array $cart = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('order_taking')) return false;
        return (bool) auth()->user()?->can('order_taking.use');
    }

    public function mount(): void
    {
        $this->orderDate = now()->toDateString();
        $this->locationId = Location::query()
            ->where('company_id', Auth::user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')->orderBy('id')
            ->value('id');
    }

    public function getCustomersProperty()
    {
        return ThirdParty::query()
            ->where('company_id', Auth::user()?->company_id)
            ->where('is_customer', true)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'document_number', 'default_price_list_id',
                   'email', 'address', 'city', 'payment_terms', 'delivery_horario']);
    }

    public function getPriceListsProperty()
    {
        return PriceList::query()
            ->where('company_id', Auth::user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function getLocationsProperty()
    {
        return Location::query()
            ->where('company_id', Auth::user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')->orderBy('name')
            ->get(['id', 'name']);
    }

    public function getSelectedCustomerProperty(): ?ThirdParty
    {
        if (! $this->customerId) return null;
        return $this->customers->firstWhere('id', $this->customerId);
    }

    public function updatedCustomerId(): void
    {
        // Al elegir cliente, auto-asignar su lista de precios si tiene.
        $customer = $this->selectedCustomer;
        if ($customer && $customer->default_price_list_id) {
            $this->priceListId = (int) $customer->default_price_list_id;
        }
    }

    public function getFoundProductsProperty()
    {
        if (! $this->priceListId || strlen(trim($this->productSearch)) < 2) return collect();

        $companyId = Auth::user()?->company_id;
        return PriceListItem::query()
            ->where('company_id', $companyId)
            ->where('price_list_id', $this->priceListId)
            ->with('product:id,code,name')
            ->whereHas('product', function ($q) {
                $s = trim($this->productSearch);
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                       ->orWhere('code', 'like', "%{$s}%");
                });
            })
            ->limit(20)
            ->get();
    }

    public function addProduct(int $priceListItemId): void
    {
        $item = PriceListItem::query()
            ->where('company_id', Auth::user()?->company_id)
            ->where('price_list_id', $this->priceListId)
            ->with('product:id,code,name')
            ->find($priceListItemId);
        if (! $item || ! $item->product) return;

        foreach ($this->cart as $i => $c) {
            if ((int) $c['product_id'] === (int) $item->product_id) {
                $this->cart[$i]['quantity']++;
                return;
            }
        }

        $this->cart[] = [
            'product_id' => (int) $item->product_id,
            'code' => (string) $item->product->code,
            'name' => (string) $item->product->name,
            'quantity' => 1,
            'price_before_tax' => (float) $item->price_before_tax,
            'tax_amount' => (float) $item->tax_amount,
            'price_at_public' => (float) $item->price_at_public,
        ];
        $this->productSearch = '';
    }

    public function removeLine(int $i): void
    {
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);
    }

    public function updateQuantity(int $i, float $qty): void
    {
        if (isset($this->cart[$i])) {
            $this->cart[$i]['quantity'] = max(0, $qty);
        }
    }

    public function getCartTotalsProperty(): array
    {
        $subtotal = 0.0; $tax = 0.0; $total = 0.0;
        foreach ($this->cart as $c) {
            $qty = (float) $c['quantity'];
            $subtotal += $qty * (float) $c['price_before_tax'];
            $tax += $qty * (float) $c['tax_amount'];
            $total += $qty * (float) $c['price_at_public'];
        }
        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total];
    }

    public function saveOrder(): void
    {
        if (! $this->customerId) {
            Notification::make()->title('Selecciona un cliente')->warning()->send();
            return;
        }
        if (empty($this->cart)) {
            Notification::make()->title('Agrega al menos una línea')->warning()->send();
            return;
        }

        $companyId = (int) Auth::user()->company_id;

        // Validar que cliente y lista pertenezcan a la empresa
        $customerOk = ThirdParty::query()
            ->where('id', $this->customerId)
            ->where('company_id', $companyId)
            ->exists();
        if (! $customerOk) {
            Notification::make()->title('Cliente inválido')->danger()->send();
            return;
        }

        try {
            $order = DB::transaction(function () use ($companyId) {
                $engine = app(OrderEngine::class);
                $number = $engine->reserveNumber($companyId, 'PED');

                $order = Order::create([
                    'company_id' => $companyId,
                    'prefix' => 'PED',
                    'number' => $number,
                    'third_party_id' => $this->customerId,
                    'price_list_id' => $this->priceListId,
                    'location_id' => $this->locationId,
                    'seller_user_id' => Auth::id(),
                    'created_by_user_id' => Auth::id(),
                    'order_date' => $this->orderDate ?? now()->toDateString(),
                    'delivery_date_expected' => $this->deliveryDateExpected ?: null,
                    'status' => Order::STATUS_DRAFT,
                    'delivery_status' => 'pending',
                    'payment_status' => 'pendiente',
                    'notes' => $this->notes ?: null,
                ]);

                foreach ($this->cart as $idx => $c) {
                    $qty = (float) $c['quantity'];
                    if ($qty <= 0) continue;
                    $priceBase = (float) $c['price_before_tax'];
                    $taxAmt = (float) $c['tax_amount'];
                    $pricePublic = (float) $c['price_at_public'];
                    $subtotal = round($qty * $priceBase, 2);
                    $taxTotal = round($qty * $taxAmt, 2);
                    $total = round($qty * $pricePublic, 2);

                    OrderItem::create([
                        'company_id' => $companyId,
                        'order_id' => $order->id,
                        'product_id' => (int) $c['product_id'],
                        'line_number' => $idx + 1,
                        'description' => "{$c['code']} — {$c['name']}",
                        'quantity_ordered' => $qty,
                        'quantity_delivered' => 0,
                        'unit_price_before_tax' => $priceBase,
                        'tax_rate' => $priceBase > 0 ? round(($taxAmt / $priceBase) * 100, 4) : 0,
                        'tax_amount' => $taxAmt,
                        'unit_price_at_public' => $pricePublic,
                        'subtotal' => $subtotal,
                        'total' => $total,
                    ]);
                }

                return $engine->recomputeTotals($order->fresh(['items']));
            });

            Notification::make()->success()->title('Pedido creado')
                ->body("Número {$order->fullNumber()}")->send();

            $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Error al crear pedido')
                ->body($e->getMessage())->persistent()->send();
        }
    }
}
