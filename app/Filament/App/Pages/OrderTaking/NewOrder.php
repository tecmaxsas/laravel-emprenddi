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

    /**
     * Retenciones del cliente, ya calculadas sobre la base gravable.
     * Cada fila: ['tax_id', 'tax_code', 'tax_name', 'tax_type', 'base_amount', 'rate', 'amount']
     */
    public array $retentions = [];

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

        $this->loadRetentionsForCustomer();
    }

    /**
     * Base gravable sobre la que se calculan las retenciones.
     *
     * No sirve usar el subtotal a secas: hay listas de precios que solo traen
     * el precio publico, sin desglose de base e IVA (el importador toma esas
     * columnas del Excel y a veces vienen vacias). En esas lineas el subtotal
     * es 0 y la retencion saldria en cero.
     *
     * Por eso se resuelve linea por linea: si la linea trae base, esa manda;
     * si no, el precio publico ES la base, porque no hay impuesto que separar.
     */
    public function getTaxableBaseProperty(): float
    {
        $base = 0.0;

        foreach ($this->cart as $c) {
            $qty = (float) $c['quantity'];
            $unitario = (float) $c['price_before_tax'] ?: (float) $c['price_at_public'];
            $base += $qty * $unitario;
        }

        return round($base, 2);
    }

    /**
     * Trae las retenciones configuradas para el cliente y las aplica.
     *
     * Reemplaza la lista completa: es la respuesta a "cambio de cliente", no un
     * recalculo. Se aplican solas para que nadie se olvide de ellas; el
     * vendedor puede quitarlas o corregir la base antes de guardar.
     */
    public function loadRetentionsForCustomer(): void
    {
        $this->retentions = app(OrderEngine::class)->suggestRetentionsFor(
            $this->selectedCustomer,
            $this->taxableBase,
        );
    }

    /**
     * Actualiza la base de las retenciones que estan en pantalla cuando cambia
     * el carrito. No vuelve a agregar las que el vendedor quito, ni pisa las
     * bases que corrigio a mano.
     */
    public function recomputeRetentionBases(): void
    {
        $base = $this->taxableBase;

        foreach ($this->retentions as $i => $r) {
            if ((bool) ($r['base_edited'] ?? false)) {
                continue;
            }

            $this->retentions[$i]['base_amount'] = $base;
            $this->retentions[$i]['amount'] = round($base * ((float) $r['rate'] / 100), 2);
        }
    }

    public function removeRetention(int $i): void
    {
        unset($this->retentions[$i]);
        $this->retentions = array_values($this->retentions);
    }

    /** Vuelve a traer todas las retenciones del cliente, si el vendedor se arrepintió. */
    public function restoreRetentions(): void
    {
        $this->loadRetentionsForCustomer();
    }

    public function updateRetentionBase(int $i, float $base): void
    {
        if (! isset($this->retentions[$i])) return;

        $base = max(0, $base);
        $this->retentions[$i]['base_amount'] = $base;
        $this->retentions[$i]['amount'] = round($base * ((float) $this->retentions[$i]['rate'] / 100), 2);
        // Marcada a mano: los recalculos por cambios del carrito ya no la pisan.
        $this->retentions[$i]['base_edited'] = true;
    }

    public function getRetentionTotalProperty(): float
    {
        return round(collect($this->retentions)->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2);
    }

    public function getFoundProductsProperty()
    {
        $search = trim($this->productSearch);
        if (! $this->priceListId || mb_strlen($search) < 2) return collect();

        // Cada palabra debe aparecer en alguno de los campos buscables (AND entre
        // palabras, OR entre campos): asi "coca 350" encuentra "Coca Cola 350ml".
        $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
        $escape = fn (string $t) => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $t);

        $companyId = Auth::user()?->company_id;
        $table = (new PriceListItem)->getTable();

        return PriceListItem::query()
            ->where('company_id', $companyId)
            ->where('price_list_id', $this->priceListId)
            ->with('product:id,code,name,barcode,brand,category_id', 'product.category:id,name')
            ->whereHas('product', function ($q) use ($terms, $escape) {
                foreach ($terms as $term) {
                    $like = '%' . $escape($term) . '%';
                    $q->where(function ($qq) use ($like) {
                        $qq->where('name', 'ilike', $like)
                           ->orWhere('code', 'ilike', $like)
                           ->orWhere('barcode', 'ilike', $like)
                           ->orWhere('brand', 'ilike', $like)
                           ->orWhere('description', 'ilike', $like)
                           ->orWhereHas('category', fn ($c) => $c->where('name', 'ilike', $like));
                    });
                }
            })
            // Primero coincidencias exactas de codigo/barcode, luego las que empiezan
            // por lo escrito, y de ultimo el resto ordenado por nombre.
            ->orderByRaw(
                "(SELECT CASE
                    WHEN p.code ILIKE ? OR p.barcode ILIKE ? THEN 0
                    WHEN p.code ILIKE ? OR p.barcode ILIKE ? THEN 1
                    ELSE 2 END
                  FROM products p WHERE p.id = \"{$table}\".product_id)",
                [$escape($search), $escape($search), $escape($search) . '%', $escape($search) . '%']
            )
            ->orderByRaw("(SELECT p.name FROM products p WHERE p.id = \"{$table}\".product_id)")
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
                $this->recomputeRetentionBases();
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
        $this->recomputeRetentionBases();
    }

    public function removeLine(int $i): void
    {
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);
        $this->recomputeRetentionBases();
    }

    public function updateQuantity(int $i, float $qty): void
    {
        if (isset($this->cart[$i])) {
            $this->cart[$i]['quantity'] = max(0, $qty);
            $this->recomputeRetentionBases();
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
                        // El IVA de la linea, no el unitario: va al lado de
                        // subtotal y total, que tambien son de la linea, y es
                        // lo que suma recomputeTotals para el IVA del pedido.
                        'tax_amount' => $taxTotal,
                        'unit_price_at_public' => $pricePublic,
                        'subtotal' => $subtotal,
                        'total' => $total,
                    ]);
                }

                $engine->syncRetentions($order, $this->retentions);

                return $engine->recomputeTotals($order->fresh(['items', 'retentions']));
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
