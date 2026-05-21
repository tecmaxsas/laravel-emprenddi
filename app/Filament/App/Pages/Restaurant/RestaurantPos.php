<?php

namespace App\Filament\App\Pages\Restaurant;

use App\Models\Account;
use App\Models\Category;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Restaurant\Modifier;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Reservation;
use App\Models\Restaurant\ServiceZone;
use App\Models\Restaurant\Table;
use App\Models\ThirdParty;
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

    // Curso "actual" al agregar items: 1=entrada, 2=principal, 3=postre, 4=bebida
    public int $currentCourse = 1;

    // Estado del modal de modificadores
    public ?int $modifierProductId = null;
    public array $modifierSelections = [];       // [groupId => [modifierId, ...]] (multi) | [groupId => modifierId] (single)
    public string $modifierItemNote = '';

    // Estado del modal mitad y mitad
    public bool $halfModalOpen = false;
    public ?int $halfAProductId = null;
    public ?int $halfBProductId = null;
    public string $halfNote = '';

    // Transferir / juntar mesas
    public bool $transferModalOpen = false;
    public ?int $transferTargetTableId = null;

    public bool $mergeModalOpen = false;
    public ?int $mergeTargetOrderId = null;

    // Modal "nueva para llevar"
    public bool $takeawayModalOpen = false;
    public string $takeawayCustomerName = '';

    // Modal "nuevo domicilio"
    public bool $deliveryModalOpen = false;
    public string $deliveryCustomerName = '';
    public string $deliveryCustomerPhone = '';
    public string $deliveryAddress = '';
    public string $deliveryAddressNotes = '';
    public string $deliveryFee = '0';

    // Cobro / facturación
    public string $customTipAmount = '';        // input manual de propina ($)
    public string $splitMode = 'none';          // 'none' | 'by_item'
    public bool $billingModalOpen = false;
    // Multi-pago: por cada tab un array de pagos
    // [tabKey => [['method' => 'cash', 'account_id' => 5, 'amount' => '50000.00'], ...]]
    public array $billingPayments = [];
    public string $billingReference = '';

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

        // Si el producto tiene grupos de modificadores Y la feature 'modifiers'
        // está activa en la empresa, abrir el modal en vez de agregar directo.
        // Si está OFF, agrega como si no tuviera modificadores.
        if (\App\Support\RestaurantSettings::isEnabled('modifiers')
            && $product->modifierGroups()->where('active', true)->exists()) {
            $this->modifierProductId = $productId;
            $this->modifierSelections = [];
            $this->modifierItemNote = '';

            // Inicializar el slot de cada grupo segun su tipo. Livewire necesita
            // saber que checkbox-group es array para hacer push/pop; si queda
            // como null o escalar trata a todos los inputs como un toggle compartido.
            foreach ($product->modifierGroups()->with(['modifiers' => fn ($q) => $q->where('active', true)])->get() as $group) {
                if ($group->isSingleSelect()) {
                    // Radio: pre-seleccionar si solo hay una opcion; sino null.
                    $this->modifierSelections[$group->id] = $group->modifiers->count() === 1
                        ? (int) $group->modifiers->first()->id
                        : null;
                } else {
                    // Checkbox: array vacio. Livewire ira anadiendo IDs al marcar.
                    $this->modifierSelections[$group->id] = [];
                }
            }
            return;
        }

        try {
            app(RestaurantOrderEngine::class)->addItem(
                $order,
                $product,
                1.0,
                null,
                [],
                $this->currentCourse,
            );
            Notification::make()
                ->title($product->name)
                ->body('Agregado a la cuenta — '.(OrderItem::COURSES[$this->currentCourse] ?? 'Curso '.$this->currentCourse))
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

    public function setCurrentCourse(int $course): void
    {
        if (! array_key_exists($course, OrderItem::COURSES)) return;
        $this->currentCourse = $course;
    }

    /**
     * Cicla el curso de un item: 1 → 2 → 3 → 4 → 1.
     * Solo permitido en items pendientes (aún no enviados a cocina).
     */
    public function cycleItemCourse(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        if ($item->kitchen_status !== OrderItem::KS_PENDING) {
            Notification::make()
                ->title('No se puede cambiar')
                ->body('El item ya fue enviado a cocina.')
                ->warning()
                ->send();
            return;
        }
        $courses = array_keys(OrderItem::COURSES);
        $idx = array_search($item->course, $courses, true);
        $next = $idx === false ? $courses[0] : $courses[($idx + 1) % count($courses)];
        $item->update(['course' => $next]);
    }

    public function getModifierProductProperty(): ?Product
    {
        if (! $this->modifierProductId) return null;
        return Product::with(['modifierGroups.modifiers' => fn ($q) => $q->where('active', true)])
            ->find($this->modifierProductId);
    }

    public function cancelModifiers(): void
    {
        $this->modifierProductId = null;
        $this->modifierSelections = [];
        $this->modifierItemNote = '';
    }

    public function confirmModifiers(): void
    {
        $order = $this->activeOrder;
        $product = $this->modifierProduct;
        if (! $order || ! $product) {
            $this->cancelModifiers();
            return;
        }

        // Validar min/max por grupo
        $groups = $product->modifierGroups()->with(['modifiers' => fn ($q) => $q->where('active', true)])->get();
        $selectedIds = [];

        foreach ($groups as $group) {
            $raw = $this->modifierSelections[$group->id] ?? null;
            $ids = is_array($raw) ? array_values(array_filter($raw)) : ($raw ? [(int) $raw] : []);
            $count = count($ids);

            if ($group->required && $count < max(1, $group->min_select)) {
                Notification::make()->title('Falta elegir')->body("'{$group->name}' requiere al menos ".max(1, $group->min_select).' opción.')->danger()->send();
                return;
            }
            if ($count < $group->min_select) {
                Notification::make()->title('Mínimo no cumplido')->body("'{$group->name}' requiere al menos {$group->min_select}.")->danger()->send();
                return;
            }
            if ($group->max_select > 0 && $count > $group->max_select) {
                Notification::make()->title('Máximo excedido')->body("'{$group->name}' permite máximo {$group->max_select}.")->danger()->send();
                return;
            }
            $selectedIds = array_merge($selectedIds, $ids);
        }

        // Resolver snapshot desde la BD (NO confiar en datos del front)
        $modifiers = [];
        if ($selectedIds) {
            foreach (Modifier::with('group')->whereIn('id', $selectedIds)->get() as $m) {
                $modifiers[] = [
                    'group_id' => $m->restaurant_modifier_group_id,
                    'group_name' => $m->group?->name,
                    'modifier_id' => $m->id,
                    'name' => $m->name,
                    'price_delta' => (float) $m->price_delta,
                ];
            }
        }

        try {
            app(RestaurantOrderEngine::class)->addItem(
                $order,
                $product,
                1.0,
                $this->modifierItemNote !== '' ? $this->modifierItemNote : null,
                $modifiers,
                $this->currentCourse,
            );

            $extra = array_sum(array_column($modifiers, 'price_delta'));
            Notification::make()
                ->title($product->name)
                ->body($extra > 0 ? 'Agregado (+$'.number_format($extra, 0).' modificadores)' : 'Agregado a la cuenta')
                ->success()
                ->duration(1500)
                ->send();

            $this->cancelModifiers();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al agregar')->body($e->getMessage())->danger()->send();
        }
    }

    // ============ MITAD Y MITAD ============

    public function openHalfModal(): void
    {
        if (! $this->activeOrder) return;
        $this->halfModalOpen = true;
        $this->halfAProductId = null;
        $this->halfBProductId = null;
        $this->halfNote = '';
    }

    public function closeHalfModal(): void
    {
        $this->halfModalOpen = false;
        $this->halfAProductId = null;
        $this->halfBProductId = null;
        $this->halfNote = '';
    }

    /**
     * Lista de productos elegibles para la mitad B: solo los de la misma
     * categoria que A. Si A no se ha elegido, retorna vacio.
     */
    public function getHalfBOptionsProperty()
    {
        if (! $this->halfAProductId) return collect();

        $a = Product::find($this->halfAProductId);
        if (! $a || ! $a->category_id) return collect();

        return Product::query()
            ->where('active', true)
            ->where('is_sellable', true)
            ->where('category_id', $a->category_id)
            ->where('id', '!=', $a->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Catalogo para la mitad A: solo productos con categoria asignada.
     */
    public function getHalfAOptionsProperty()
    {
        return Product::query()
            ->where('active', true)
            ->where('is_sellable', true)
            ->whereNotNull('category_id')
            ->orderBy('name')
            ->get();
    }

    public function getHalfPreviewProperty(): ?array
    {
        if (! $this->halfAProductId || ! $this->halfBProductId) return null;

        $a = Product::find($this->halfAProductId);
        $b = Product::find($this->halfBProductId);
        if (! $a || ! $b) return null;

        $location = $this->activeOrder?->location_id ? \App\Models\Location::find($this->activeOrder->location_id) : null;
        $priceA = (float) $a->priceForLocation($location);
        $priceB = (float) $b->priceForLocation($location);

        return [
            'a' => $a->name,
            'b' => $b->name,
            'price_a' => $priceA,
            'price_b' => $priceB,
            'final_price' => max($priceA, $priceB),
            'description' => "1/2 {$a->name} + 1/2 {$b->name}",
        ];
    }

    public function confirmHalfAndHalf(): void
    {
        $order = $this->activeOrder;
        if (! $order) { $this->closeHalfModal(); return; }
        if (! $this->halfAProductId || ! $this->halfBProductId) {
            Notification::make()->title('Faltan mitades')->body('Debes elegir las 2 mitades.')->danger()->send();
            return;
        }

        $a = Product::find($this->halfAProductId);
        $b = Product::find($this->halfBProductId);
        if (! $a || ! $b) {
            Notification::make()->title('Productos invalidos')->danger()->send();
            return;
        }

        try {
            app(RestaurantOrderEngine::class)->addHalfAndHalf(
                $order,
                $a,
                $b,
                $this->currentCourse,
                $this->halfNote !== '' ? $this->halfNote : null,
            );

            Notification::make()
                ->title('Mitad y mitad agregada')
                ->body("1/2 {$a->name} + 1/2 {$b->name}")
                ->success()
                ->duration(2000)
                ->send();

            $this->closeHalfModal();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    // ============ TRANSFERIR MESA ============

    public function openTransferModal(): void
    {
        if (! $this->activeOrder) return;
        $this->transferModalOpen = true;
        $this->transferTargetTableId = null;
    }

    public function closeTransferModal(): void
    {
        $this->transferModalOpen = false;
        $this->transferTargetTableId = null;
    }

    /**
     * Mesas libres en la misma sede, excluyendo la actual.
     */
    public function getTransferTablesProperty()
    {
        $order = $this->activeOrder;
        if (! $order) return collect();

        return Table::query()
            ->where('active', true)
            ->where('location_id', $order->location_id)
            ->where('id', '!=', $order->table_id)
            ->where('status', 'free')
            ->with('zone')
            ->orderBy('code')
            ->get();
    }

    public function confirmTransfer(): void
    {
        $order = $this->activeOrder;
        if (! $order || ! $this->transferTargetTableId) {
            Notification::make()->title('Selecciona una mesa')->danger()->send();
            return;
        }

        $newTable = Table::find($this->transferTargetTableId);
        if (! $newTable) {
            Notification::make()->title('Mesa invalida')->danger()->send();
            return;
        }

        try {
            $oldCode = $order->table?->code ?? '—';
            app(RestaurantOrderEngine::class)->transferOrder($order, $newTable);
            Notification::make()
                ->title('Mesa transferida')
                ->body("Orden movida de {$oldCode} → {$newTable->code}")
                ->success()
                ->send();
            $this->closeTransferModal();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al transferir')->body($e->getMessage())->danger()->send();
        }
    }

    // ============ JUNTAR MESAS ============

    public function openMergeModal(): void
    {
        if (! $this->activeOrder) return;
        $this->mergeModalOpen = true;
        $this->mergeTargetOrderId = null;
    }

    public function closeMergeModal(): void
    {
        $this->mergeModalOpen = false;
        $this->mergeTargetOrderId = null;
    }

    /**
     * Ordenes abiertas en otras mesas (no la actual) de la misma sede.
     */
    public function getMergeOrdersProperty()
    {
        $order = $this->activeOrder;
        if (! $order) return collect();

        return Order::query()
            ->whereIn('status', [Order::STATUS_OPEN, Order::STATUS_IN_KITCHEN, Order::STATUS_SERVED])
            ->where('location_id', $order->location_id)
            ->where('id', '!=', $order->id)
            ->with(['table', 'items'])
            ->orderBy('opened_at')
            ->get();
    }

    public function confirmMerge(): void
    {
        $order = $this->activeOrder;
        if (! $order || ! $this->mergeTargetOrderId) {
            Notification::make()->title('Selecciona una orden')->danger()->send();
            return;
        }

        $secondary = Order::find($this->mergeTargetOrderId);
        if (! $secondary) {
            Notification::make()->title('Orden invalida')->danger()->send();
            return;
        }

        try {
            $secCode = $secondary->table?->code ?? '—';
            app(RestaurantOrderEngine::class)->mergeOrders($order, $secondary);
            Notification::make()
                ->title('Mesas fusionadas')
                ->body("Items de mesa {$secCode} traidos a {$order->table?->code}. Mesa {$secCode} liberada.")
                ->success()
                ->send();
            $this->closeMergeModal();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al fusionar')->body($e->getMessage())->danger()->send();
        }
    }

    // ============ TAKEAWAY / SERVICE MODE ============

    public function openTakeawayPrompt(): void
    {
        if (! $this->locationId) {
            Notification::make()->title('Selecciona una sede primero')->danger()->send();
            return;
        }
        $this->takeawayModalOpen = true;
        $this->takeawayCustomerName = '';
    }

    public function closeTakeawayPrompt(): void
    {
        $this->takeawayModalOpen = false;
        $this->takeawayCustomerName = '';
    }

    public function createTakeaway(): void
    {
        if (! $this->locationId) return;

        $location = Location::find($this->locationId);
        if (! $location) {
            Notification::make()->title('Sede inválida')->danger()->send();
            return;
        }

        try {
            $name = trim($this->takeawayCustomerName);
            $order = app(RestaurantOrderEngine::class)->openTakeawayOrder(
                $location,
                1,
                $name !== '' ? $name : null,
            );
            $this->activeOrderId = $order->id;
            $this->closeTakeawayPrompt();

            Notification::make()
                ->title('Orden para llevar abierta')
                ->body($name !== '' ? "Cliente: {$name}" : 'Agrega items y envía a cocina')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al abrir')->body($e->getMessage())->danger()->send();
        }
    }

    public function setServiceMode(string $mode): void
    {
        $order = $this->activeOrder;
        if (! $order) return;
        try {
            app(RestaurantOrderEngine::class)->setServiceMode($order, $mode);
            Notification::make()
                ->title($mode === 'takeaway' ? '🥡 Para llevar' : '🍽️ Comer aquí')
                ->success()
                ->duration(1500)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    // ============ DOMICILIOS ============

    public function openDeliveryPrompt(): void
    {
        if (! $this->locationId) {
            Notification::make()->title('Selecciona una sede primero')->danger()->send();
            return;
        }
        $this->deliveryModalOpen = true;
        $this->deliveryCustomerName = '';
        $this->deliveryCustomerPhone = '';
        $this->deliveryAddress = '';
        $this->deliveryAddressNotes = '';
        $this->deliveryFee = '0';
    }

    public function closeDeliveryPrompt(): void
    {
        $this->deliveryModalOpen = false;
    }

    public function createDelivery(): void
    {
        if (! $this->locationId) return;

        $location = Location::find($this->locationId);
        if (! $location) {
            Notification::make()->title('Sede inválida')->danger()->send();
            return;
        }

        $name = trim($this->deliveryCustomerName);
        $address = trim($this->deliveryAddress);
        if ($name === '' || $address === '') {
            Notification::make()->title('Faltan datos')->body('Nombre y dirección son obligatorios.')->danger()->send();
            return;
        }

        $fee = (float) str_replace([',', '.'], ['', '.'], $this->deliveryFee);

        try {
            $order = app(RestaurantOrderEngine::class)->openDeliveryOrder(
                $location,
                $name,
                $address,
                trim($this->deliveryCustomerPhone) ?: null,
                trim($this->deliveryAddressNotes) ?: null,
                max(0, $fee),
            );

            $this->activeOrderId = $order->id;
            $this->closeDeliveryPrompt();

            Notification::make()
                ->title('Domicilio abierto')
                ->body("Cliente: {$name}. Agrega items, asigna repartidor y envía.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al abrir')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Lista de domicilios activos para mostrar como cards arriba del mapa.
     */
    public function getDeliveryOrdersProperty()
    {
        if (! $this->locationId) return collect();

        return Order::query()
            ->whereIn('status', [Order::STATUS_OPEN, Order::STATUS_IN_KITCHEN, Order::STATUS_SERVED, Order::STATUS_BILLING])
            ->where('location_id', $this->locationId)
            ->where('is_delivery', true)
            ->with('items')
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * Lista de órdenes para llevar / pickup activas (sin mesa asignada).
     * Mostradas como cards arriba del mapa para que el cajero pueda
     * volver a cualquiera con un click.
     */
    public function getTakeawayOrdersProperty()
    {
        if (! $this->locationId) return collect();

        return Order::query()
            ->whereIn('status', [Order::STATUS_OPEN, Order::STATUS_IN_KITCHEN, Order::STATUS_SERVED, Order::STATUS_BILLING])
            ->where('location_id', $this->locationId)
            ->where('is_takeaway', true)
            ->whereNull('table_id')
            ->with('items')
            ->orderBy('opened_at')
            ->get();
    }

    // ============ RESERVACIONES ============

    /**
     * Reservaciones activas en los proximos 120 minutos (incluye ya-en-hora ±30 min).
     */
    public function getUpcomingReservationsProperty()
    {
        if (! $this->locationId) return collect();

        $now = now();
        return Reservation::query()
            ->where('location_id', $this->locationId)
            ->whereIn('status', [Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED])
            ->whereBetween('reserved_for', [
                $now->copy()->subMinutes(30),
                $now->copy()->addMinutes(120),
            ])
            ->with(['table', 'zone'])
            ->orderBy('reserved_for')
            ->get();
    }

    /**
     * Sentar cliente: abre orden en la mesa reservada (o le pide al cajero que
     * elija una si no hay mesa especifica) y linkea la Reservation -> Order.
     */
    public function seatReservation(int $reservationId): void
    {
        $reservation = Reservation::find($reservationId);
        if (! $reservation) return;
        if (! $reservation->isActive()) {
            Notification::make()->title('Reserva no activa')->warning()->send();
            return;
        }

        // Sin mesa especifica: solo marcar seated; el host elige mesa manualmente
        if (! $reservation->table_id) {
            $reservation->update([
                'status' => Reservation::STATUS_SEATED,
                'seated_at' => now(),
            ]);
            Notification::make()
                ->title('Reserva marcada como sentado')
                ->body("{$reservation->customer_name} llegó. Asigna mesa manualmente.")
                ->success()
                ->send();
            return;
        }

        $table = Table::find($reservation->table_id);
        if (! $table) {
            Notification::make()->title('Mesa de la reserva ya no existe')->danger()->send();
            return;
        }

        // Si la mesa ya tiene orden activa, no podemos abrir otra — solo abrir esa
        $existing = $table->activeOrder();
        if ($existing) {
            $this->activeOrderId = $existing->id;
            $reservation->update([
                'status' => Reservation::STATUS_SEATED,
                'seated_at' => now(),
                'seated_order_id' => $existing->id,
            ]);
            Notification::make()
                ->title('Mesa ya estaba ocupada')
                ->body("Vinculada a orden existente {$existing->fullNumber()}.")
                ->info()
                ->send();
            return;
        }

        try {
            $order = app(RestaurantOrderEngine::class)->openTableOrder(
                $table,
                null,
                max(1, (int) $reservation->guests),
            );

            $reservation->update([
                'status' => Reservation::STATUS_SEATED,
                'seated_at' => now(),
                'seated_order_id' => $order->id,
            ]);

            // Heredar nombre del cliente como nota
            $order->update([
                'notes' => trim(($order->notes ?? '')."\nReserva: {$reservation->customer_name}"
                    .($reservation->customer_phone ? " ({$reservation->customer_phone})" : '')),
            ]);

            $this->activeOrderId = $order->id;

            Notification::make()
                ->title("Bienvenido {$reservation->customer_name}")
                ->body("Mesa {$table->code} abierta para {$reservation->guests} comensal(es).")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al sentar')->body($e->getMessage())->danger()->send();
        }
    }

    public function markReservationNoShow(int $reservationId): void
    {
        $reservation = Reservation::find($reservationId);
        if (! $reservation || ! $reservation->isActive()) return;
        $reservation->update([
            'status' => Reservation::STATUS_NO_SHOW,
            'cancelled_at' => now(),
        ]);
        Notification::make()->title("Marcada como no-show: {$reservation->customer_name}")->warning()->send();
    }

    // ============ PROPINA / SPLIT / COBRO ============

    public function applyTipPercent(int $percentage): void
    {
        $order = $this->activeOrder;
        if (! $order) return;
        try {
            app(RestaurantOrderEngine::class)->setTip($order, (float) $percentage);
            $this->customTipAmount = '';
        } catch (\Throwable $e) {
            Notification::make()->title('Error propina')->body($e->getMessage())->danger()->send();
        }
    }

    public function applyCustomTip(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;
        $amount = (float) str_replace(['.', ','], ['', '.'], $this->customTipAmount);
        if ($amount < 0) {
            Notification::make()->title('Monto inválido')->danger()->send();
            return;
        }
        try {
            app(RestaurantOrderEngine::class)->setTip($order, null, $amount);
        } catch (\Throwable $e) {
            Notification::make()->title('Error propina')->body($e->getMessage())->danger()->send();
        }
    }

    public function setSplitMode(string $mode): void
    {
        if (! in_array($mode, ['none', 'by_item'], true)) return;
        $this->splitMode = $mode;
        // Si vuelven a 'none', limpiar etiquetas de todos los items
        if ($mode === 'none' && $this->activeOrder) {
            foreach ($this->activeOrder->items as $it) {
                if ($it->split_tab) {
                    $it->update(['split_tab' => null]);
                }
            }
        }
    }

    public function assignItemTab(int $itemId, string $tab): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        $clean = trim($tab);
        if ($clean === '') return;
        // Solo aceptar A-Z, 1-9 mayúsculas para mantenerlo simple
        $clean = strtoupper(substr($clean, 0, 5));
        app(RestaurantOrderEngine::class)->setItemTab($item, $clean);
    }

    public function unassignItemTab(int $itemId): void
    {
        $item = OrderItem::find($itemId);
        if (! $item) return;
        app(RestaurantOrderEngine::class)->setItemTab($item, null);
    }

    /**
     * Asegura un ThirdParty "Consumidor Final" para órdenes sin cliente.
     */
    protected function ensureDefaultCustomer(): ThirdParty
    {
        return ThirdParty::firstOrCreate(
            [
                'company_id' => Auth::user()->company_id,
                'document_number' => '222222222',
            ],
            [
                'person_type' => 'natural',
                'document_type' => 'cc',
                'name' => 'Consumidor Final',
                'is_customer' => true,
                'is_supplier' => false,
                'active' => true,
            ],
        );
    }

    /**
     * Estructura de tabs para facturar.
     * - 'none': 1 tab con TODOS los items activos.
     * - 'by_item': 1 tab por cada valor único de split_tab; los items sin tag
     *   van a un tab 'Sin asignar'.
     */
    public function getBillingTabsProperty(): array
    {
        $order = $this->activeOrder;
        if (! $order) return [];

        $items = $order->items->reject(fn ($i) => $i->kitchen_status === OrderItem::KS_CANCELLED);
        $orderSubtotal = (float) $items->sum('subtotal');
        $orderTip = (float) $order->tip_amount;

        if ($this->splitMode === 'none') {
            $taxSum = (float) $items->sum('tax_amount');
            $totalSum = (float) $items->sum('total');
            $tipShare = $orderTip;
            return [[
                'key' => 'main',
                'label' => null,
                'items' => $items->values(),
                'subtotal' => $orderSubtotal,
                'tax' => $taxSum,
                'tip_share' => $tipShare,
                'invoice_total' => $totalSum,        // lo que va a la factura
                'grand_total' => $totalSum + $tipShare, // con propina
            ]];
        }

        // by_item
        $grouped = $items->groupBy(fn ($i) => $i->split_tab ?: '—');
        $tabs = [];
        foreach ($grouped as $label => $group) {
            $sub = (float) $group->sum('subtotal');
            $tax = (float) $group->sum('tax_amount');
            $total = (float) $group->sum('total');
            $tipShare = $orderSubtotal > 0
                ? round($orderTip * $sub / $orderSubtotal, 2)
                : 0;
            $tabs[] = [
                'key' => $label,
                'label' => $label === '—' ? 'Sin asignar' : $label,
                'items' => $group->values(),
                'subtotal' => $sub,
                'tax' => $tax,
                'tip_share' => $tipShare,
                'invoice_total' => $total,
                'grand_total' => $total + $tipShare,
                'unassigned' => $label === '—',
            ];
        }
        return $tabs;
    }

    public function getPaymentMethodOptionsProperty(): array
    {
        return \App\Models\Payment::PAYMENT_METHODS;
    }

    /**
     * Cuentas contables aceptables para recibir pago (caja + bancos).
     * Filtra por accepts_movements para que no haya errores al postear.
     */
    public function getCashAccountOptionsProperty()
    {
        return Account::query()
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where(function ($q) {
                $q->where('code', 'like', '11%')   // disponible
                  ->orWhere('code', 'like', '1305%'); // CxC
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    protected function defaultCashAccountId(?string $method = null): ?int
    {
        // 1. Si el metodo tiene PaymentMethod configurado, usar su account_id
        if ($method) {
            $configured = PaymentMethod::query()
                ->where('code', $method)
                ->where('active', true)
                ->value('account_id');
            if ($configured) return (int) $configured;
        }

        // 2. Fallback: caja general o primera cuenta disponible
        return Account::query()
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', 'like', '110505%')
            ->value('id') ?? Account::query()
                ->where('accepts_movements', true)
                ->where('active', true)
                ->where('code', 'like', '11%')
                ->value('id');
    }

    public function openBillingModal(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        $tabs = $this->billingTabs;
        if (empty($tabs)) {
            Notification::make()->title('Nada para cobrar')->warning()->send();
            return;
        }

        // Validar que en by_item no haya items sin asignar
        if ($this->splitMode === 'by_item') {
            foreach ($tabs as $t) {
                if (! empty($t['unassigned'])) {
                    Notification::make()
                        ->title('Hay items sin asignar')
                        ->body('Asigna una etiqueta (A, B, …) a cada item antes de cobrar.')
                        ->danger()->send();
                    return;
                }
            }
        }

        $defaultAccount = $this->defaultCashAccountId('cash');

        // Inicializar pagos: un solo pago en efectivo por el total del tab
        $this->billingPayments = [];
        foreach ($tabs as $t) {
            $this->billingPayments[$t['key']] = [[
                'method' => 'cash',
                'account_id' => $defaultAccount,
                'amount' => number_format((float) $t['invoice_total'], 2, '.', ''),
            ]];
        }
        $this->billingReference = '';
        $this->billingModalOpen = true;
    }

    public function closeBillingModal(): void
    {
        $this->billingModalOpen = false;
    }

    /**
     * Agrega una linea de pago vacia al tab. El monto sugerido es el saldo
     * pendiente (target - suma actual de pagos del tab).
     */
    public function addPaymentLine(string $tabKey): void
    {
        $tabs = $this->billingTabs;
        $tab = collect($tabs)->firstWhere('key', $tabKey);
        if (! $tab) return;

        $current = collect($this->billingPayments[$tabKey] ?? [])
            ->sum(fn ($p) => (float) ($p['amount'] ?? 0));
        $remaining = max(0, round((float) $tab['invoice_total'] - $current, 2));

        $this->billingPayments[$tabKey][] = [
            'method' => 'cash',
            'account_id' => $this->defaultCashAccountId('cash'),
            'amount' => number_format($remaining, 2, '.', ''),
        ];
    }

    public function removePaymentLine(string $tabKey, int $index): void
    {
        if (! isset($this->billingPayments[$tabKey][$index])) return;
        // No permitir borrar el ultimo pago — debe quedar al menos uno
        if (count($this->billingPayments[$tabKey]) <= 1) return;

        unset($this->billingPayments[$tabKey][$index]);
        $this->billingPayments[$tabKey] = array_values($this->billingPayments[$tabKey]);
    }

    /**
     * Al cambiar de metodo de pago en una linea, sugerir la cuenta default.
     */
    public function onPaymentMethodChange(string $tabKey, int $index, string $method): void
    {
        if (! isset($this->billingPayments[$tabKey][$index])) return;
        $account = $this->defaultCashAccountId($method);
        if ($account) {
            $this->billingPayments[$tabKey][$index]['account_id'] = $account;
        }
    }

    public function confirmBilling(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        $tabs = $this->billingTabs;
        if (empty($tabs)) return;

        // Validar cada pago por tab: metodo, cuenta, monto > 0 y suma = invoice_total
        foreach ($tabs as $t) {
            $payments = $this->billingPayments[$t['key']] ?? [];
            if (empty($payments)) {
                Notification::make()
                    ->title('Sin pagos')
                    ->body('Tab '.($t['label'] ?? 'principal').' no tiene ningún pago.')
                    ->danger()->send();
                return;
            }
            $sum = 0;
            foreach ($payments as $p) {
                if (empty($p['method'])) {
                    Notification::make()->title('Falta método')->body('Una línea de pago no tiene método.')->danger()->send();
                    return;
                }
                if (empty($p['account_id'])) {
                    Notification::make()->title('Falta cuenta')->body('Una línea de pago no tiene cuenta contable.')->danger()->send();
                    return;
                }
                $amount = (float) ($p['amount'] ?? 0);
                if ($amount <= 0) {
                    Notification::make()->title('Monto inválido')->body('Hay una línea de pago con monto 0.')->danger()->send();
                    return;
                }
                $sum += $amount;
            }
            $diff = round((float) $t['invoice_total'] - $sum, 2);
            if (abs($diff) > 0.01) {
                $tabName = $t['label'] ?: 'principal';
                Notification::make()
                    ->title('Pagos no cuadran')
                    ->body("Tab {$tabName}: faltan o sobran $".number_format(abs($diff), 0, ',', '.')." (objetivo: $".number_format($t['invoice_total'], 0, ',', '.').")")
                    ->danger()->send();
                return;
            }
        }

        $thirdPartyId = $this->ensureDefaultCustomer()->id;

        // Construir payload con pagos detallados
        $payload = [];
        foreach ($tabs as $t) {
            $payload[] = [
                'label' => $t['label'],
                'item_ids' => $t['items']->pluck('id')->all(),
                'reference' => $this->billingReference !== '' ? $this->billingReference : null,
                'payments' => array_map(fn ($p) => [
                    'payment_method' => $p['method'],
                    'account_id' => (int) $p['account_id'],
                    'amount' => round((float) $p['amount'], 2),
                ], $this->billingPayments[$t['key']]),
            ];
        }

        try {
            $invoices = app(RestaurantOrderEngine::class)->bill($order, $payload, $thirdPartyId);

            $numbers = implode(', ', array_map(fn ($i) => $i->fullNumber(), $invoices));
            Notification::make()
                ->title('Cuenta cobrada')
                ->body(count($invoices).' factura(s) generada(s): '.$numbers)
                ->success()
                ->send();

            $this->flushBrowserPrintJobs();
            $this->billingModalOpen = false;
            $this->closeOrderPanel();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al cobrar')->body($e->getMessage())->danger()->send();
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

    /**
     * Vacía la cola de impresión browser (QZ Tray) y la despacha al front.
     * Llamar después de cualquier acción que pueda generar tickets/recibos.
     */
    protected function flushBrowserPrintJobs(): void
    {
        $jobs = app(\App\Services\Restaurant\BrowserPrintQueue::class)->flush();
        if (! empty($jobs)) {
            $this->dispatch('qz-print-jobs', jobs: $jobs);
        }
    }

    /**
     * @param  int|null  $course  Si se pasa, solo envía items pendientes de ese curso.
     */
    public function sendToKitchen(?int $course = null): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        try {
            $tickets = app(RestaurantOrderEngine::class)->sendPendingToKitchen($order, $course);
            $count = count($tickets);
            $label = $course
                ? (OrderItem::COURSES[$course] ?? 'Curso '.$course)
                : 'Todo lo pendiente';

            Notification::make()
                ->title("Comanda enviada · {$label}")
                ->body($count > 0
                    ? "{$count} ticket(s) generado(s) por impresora."
                    : "Items marcados como enviados (sin impresora asignada).")
                ->success()
                ->send();

            $this->flushBrowserPrintJobs();
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
        if (! Auth::user()?->can('restaurant.order.cancel')) {
            Notification::make()
                ->title('Sin permiso')
                ->body('No tienes permiso para cancelar órdenes. Pide al administrador.')
                ->danger()->send();
            return;
        }

        try {
            app(RestaurantOrderEngine::class)->cancel($order, 'Cancelado desde POS por '.(Auth::user()?->name ?? 'usuario'));
            Notification::make()->title('Orden cancelada')->warning()->send();
            $this->closeOrderPanel();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Cierra la cuenta SIN facturar (casa invita). Para flujo normal con
     * factura usar confirmBilling(). Requiere permiso explicito porque
     * implica perdida fiscal/contable (ingreso no registrado).
     */
    public function closeOrder(): void
    {
        $order = $this->activeOrder;
        if (! $order) return;
        if (! Auth::user()?->can('restaurant.order.close_without_invoice')) {
            Notification::make()
                ->title('Sin permiso')
                ->body('No tienes permiso para cerrar sin facturar. Pide al administrador.')
                ->danger()->send();
            return;
        }

        try {
            app(RestaurantOrderEngine::class)->close(
                $order,
                'Casa invita — cerrado sin facturar por '.(Auth::user()?->name ?? 'usuario'),
            );
            Notification::make()
                ->title('Cuenta cerrada sin factura')
                ->body("Mesa {$order->table?->code} liberada. La orden quedó registrada como 'casa invita'.")
                ->warning()
                ->send();
            $this->closeOrderPanel();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }
}
