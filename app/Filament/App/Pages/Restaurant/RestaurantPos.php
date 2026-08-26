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

    // Caja registradora
    public string $openCajaAmount = '0';
    public bool $closeCajaModalOpen = false;
    public string $closeCajaCounted = '0';
    public string $closeCajaNotes = '';

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
    public string $billingInvoiceKind = 'pos';  // pos | electronic

    // ----------------------------------------------------------------
    // PROMOCIONES (modulo opcional — ver PromotionsSettings)
    // ----------------------------------------------------------------
    // Codigo de cupon ingresado manualmente. Si esta vacio, solo se aplican
    // promociones automaticas (sin codigo).
    public string $couponCode = '';
    // Log de promociones aplicadas a la orden actual.
    // Cada item: ['promotion_id', 'name', 'code', 'discount']
    public array $appliedPromotions = [];
    // Total descontado por promociones — se distribuye proporcionalmente
    // entre las lineas de cada tab al facturar.
    public float $promotionsDiscountAmount = 0.0;

    // ----------------------------------------------------------------
    // GIFT CARDS (modulo opcional — ver GiftCardsSettings)
    // ----------------------------------------------------------------
    // Codigo en validacion (input del cajero)
    public string $giftCardCodeInput = '';
    // Gift cards aplicadas como medio de pago a la orden actual.
    // Cada item: ['gift_card_id', 'code', 'amount', 'available_balance']
    public array $appliedGiftCards = [];

    // Modal de emision: cuando el cajero agrega el producto especial 'GIFTCARD'
    // a la orden, se abre este modal para capturar monto y destinatario.
    public bool $showGiftCardEmissionModal = false;
    public ?float $giftCardEmissionAmount = null;
    public string $giftCardEmissionRecipientName = '';
    public string $giftCardEmissionRecipientEmail = '';
    public string $giftCardEmissionSenderName = '';

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! AccountantContext::ready()) return false;
        return (bool) Auth::user()?->can('restaurant.use');
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

        // Tipo de factura por defecto desde settings.pos.default_invoice_kind.
        // Empresas que solo facturan electronicamente lo dejan en 'electronic'
        // para no tener que cambiar el tipo en cada cobro.
        $settings = \App\Models\Company::find(auth()->user()->company_id)?->settings ?? [];
        $defaultKind = data_get($settings, 'pos.default_invoice_kind', 'pos');
        $this->billingInvoiceKind = in_array($defaultKind, ['pos', 'electronic'], true)
            ? $defaultKind
            : 'pos';
    }

    // ============ CAJA REGISTRADORA ============

    /**
     * Sesión de caja abierta del usuario actual (o null). El POS no opera
     * sin caja abierta — la vista muestra el panel de apertura.
     */
    public function getCashSessionProperty(): ?\App\Models\CashRegisterSession
    {
        return \App\Support\CashSessionGate::currentOpenSession();
    }

    /**
     * Resumen de la sesión (ventas, egresos, efectivo esperado).
     */
    public function getCashSummaryProperty(): ?array
    {
        $s = $this->cashSession;
        return $s ? app(\App\Services\Cash\CashSessionSummary::class)->compute($s) : null;
    }

    public function openCaja(): void
    {
        if ($this->cashSession) {
            Notification::make()->title('Ya tienes una caja abierta')->warning()->send();
            return;
        }
        if (! $this->locationId) {
            Notification::make()->title('Selecciona una sede')->danger()->send();
            return;
        }

        \App\Models\CashRegisterSession::create([
            'company_id' => Auth::user()->company_id,
            'location_id' => $this->locationId,
            'cashier_user_id' => Auth::id(),
            'status' => \App\Models\CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => max(0, (float) $this->openCajaAmount),
            'opening_notes' => null,
        ]);

        unset($this->cashSession);
        $this->openCajaAmount = '0';
        Notification::make()->title('Caja abierta')->success()->send();
    }

    public function openCloseCajaModal(): void
    {
        if (! $this->cashSession) return;
        $this->closeCajaCounted = '0';
        $this->closeCajaNotes = '';
        $this->closeCajaModalOpen = true;
    }

    public function closeCajaModal(): void
    {
        $this->closeCajaModalOpen = false;
    }

    public function closeCaja(): void
    {
        $session = $this->cashSession;
        if (! $session) return;

        if (! Auth::user()?->can('pos.cash_close')) {
            Notification::make()->title('Sin permiso para cerrar caja')->danger()->send();
            return;
        }

        // Bloquear el cierre si quedan órdenes abiertas en esta caja.
        $openOrders = Order::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('cash_register_session_id', $session->id)
            ->whereIn('status', [
                Order::STATUS_OPEN, Order::STATUS_IN_KITCHEN,
                Order::STATUS_SERVED, Order::STATUS_BILLING,
            ])
            ->count();
        if ($openOrders > 0) {
            Notification::make()
                ->title('Hay órdenes abiertas')
                ->body("Cobra o cancela las {$openOrders} órdenes activas antes de cerrar caja.")
                ->danger()->send();
            return;
        }

        $summary = app(\App\Services\Cash\CashSessionSummary::class)->compute($session);
        $counted = (float) $this->closeCajaCounted;
        $expected = (float) $summary['expected_cash'];
        $difference = round($counted - $expected, 2);

        $session->update([
            'status' => \App\Models\CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => Auth::id(),
            'closing_expected' => $expected,
            'closing_counted' => $counted,
            'closing_difference' => $difference,
            'total_sales' => $summary['sales']['total'],
            'invoice_count' => $summary['sales']['count'],
            'payment_breakdown' => $summary['payment_breakdown'],
            'closing_notes' => trim($this->closeCajaNotes) ?: null,
        ]);

        unset($this->cashSession);
        $this->closeCajaModalOpen = false;
        $this->closeOrderPanel();

        Notification::make()
            ->title('Caja cerrada')
            ->body(sprintf(
                'Esperado $%s · Contado $%s · Diferencia $%s',
                number_format($expected, 0, ',', '.'),
                number_format($counted, 0, ',', '.'),
                number_format($difference, 0, ',', '.'),
            ))
            ->{$difference == 0.0 ? 'success' : 'warning'}()
            ->send();
    }

    // ============ DATA ============

    public function getZonesProperty()
    {
        return ServiceZone::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->orderBy('display_order')
            ->get();
    }

    public function getTablesProperty()
    {
        return Table::query()
            ->where('company_id', auth()->user()?->company_id)
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
        return Order::query()
            ->where('company_id', auth()->user()?->company_id)
            ->with(['items.product', 'table', 'zone', 'server'])
            ->find($this->activeOrderId);
    }

    public function getCategoriesProperty()
    {
        return Category::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function getCatalogProperty()
    {
        return Product::query()
            ->where('company_id', auth()->user()?->company_id)
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
        $table = Table::query()->where('company_id', auth()->user()?->company_id)->find($tableId);
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
        // Limpiar el cache de la computed property: si activeOrder ya se
        // accedió en este request quedó memoizada con la orden vieja y el
        // panel seguiría mostrándola tras cancelar/cerrar.
        unset($this->activeOrder);
        // Limpiar estado de promociones y gift cards (siguiente orden empieza limpia)
        $this->couponCode = '';
        $this->appliedPromotions = [];
        $this->promotionsDiscountAmount = 0.0;
        $this->giftCardCodeInput = '';
        $this->appliedGiftCards = [];
        $this->showGiftCardEmissionModal = false;
        $this->giftCardEmissionAmount = null;
        $this->giftCardEmissionRecipientName = '';
        $this->giftCardEmissionRecipientEmail = '';
        $this->giftCardEmissionSenderName = '';
    }

    public function addProduct(int $productId): void
    {
        $order = $this->activeOrder;
        if (! $order) return;

        $product = Product::query()->where('company_id', auth()->user()?->company_id)->find($productId);
        if (! $product) return;

        // Si es el producto especial 'Tarjeta Regalo', abrir modal de emision
        // en vez de agregar como producto normal. Al confirmar el modal, se
        // agrega al order con metadata para que confirmBilling() lo emita
        // como gift card real despues de facturar.
        if (\App\Services\GiftCards\GiftCardProductProvisioner::isGiftCardProduct($product)) {
            if (! \App\Support\GiftCardsSettings::moduleActive()) {
                Notification::make()
                    ->title('Gift Cards no esta activo')
                    ->body('Activalo en Configuraciones → Gift Cards para vender tarjetas regalo.')
                    ->warning()
                    ->send();
                return;
            }
            $this->giftCardEmissionAmount = null;
            $this->giftCardEmissionRecipientName = '';
            $this->giftCardEmissionRecipientEmail = '';
            $this->giftCardEmissionSenderName = '';
            $this->showGiftCardEmissionModal = true;
            return;
        }

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
        // OrderItem no tiene company_id: scopeamos via el order activo
        // que si esta filtrado por company. Un itemId de otra empresa
        // no encontrara match aqui.
        $item = $this->activeOrder?->items()->find($itemId);
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
        return Product::query()
            ->where('company_id', auth()->user()?->company_id)
            ->with(['modifierGroups.modifiers' => fn ($q) => $q->where('active', true)])
            ->find($this->modifierProductId);
    }

    public function cancelModifiers(): void
    {
        $this->modifierProductId = null;
        $this->modifierSelections = [];
        $this->modifierItemNote = '';
        // Limpiar cache de la computed property — sin esto el modal sigue
        // abierto tras "Agregar a la cuenta" (modifierProduct quedó memoizado).
        unset($this->modifierProduct);
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
            foreach (Modifier::query()->where('company_id', auth()->user()?->company_id)->with('group')->whereIn('id', $selectedIds)->get() as $m) {
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

        $companyId = auth()->user()?->company_id;
        $a = Product::query()->where('company_id', $companyId)->find($this->halfAProductId);
        if (! $a || ! $a->category_id) return collect();

        return Product::query()
            ->where('company_id', $companyId)
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
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->where('is_sellable', true)
            ->whereNotNull('category_id')
            ->orderBy('name')
            ->get();
    }

    public function getHalfPreviewProperty(): ?array
    {
        if (! $this->halfAProductId || ! $this->halfBProductId) return null;

        $companyId = auth()->user()?->company_id;
        $a = Product::query()->where('company_id', $companyId)->find($this->halfAProductId);
        $b = Product::query()->where('company_id', $companyId)->find($this->halfBProductId);
        if (! $a || ! $b) return null;

        $location = $this->activeOrder?->location_id
            ? \App\Models\Location::query()->where('company_id', $companyId)->find($this->activeOrder->location_id)
            : null;
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

        $companyId = auth()->user()?->company_id;
        $a = Product::query()->where('company_id', $companyId)->find($this->halfAProductId);
        $b = Product::query()->where('company_id', $companyId)->find($this->halfBProductId);
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
            ->where('company_id', auth()->user()?->company_id)
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

        $newTable = Table::query()->where('company_id', auth()->user()?->company_id)->find($this->transferTargetTableId);
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
            ->where('company_id', auth()->user()?->company_id)
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

        $secondary = Order::query()->where('company_id', auth()->user()?->company_id)->find($this->mergeTargetOrderId);
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

        $location = Location::query()->where('company_id', auth()->user()?->company_id)->find($this->locationId);
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

        $location = Location::query()->where('company_id', auth()->user()?->company_id)->find($this->locationId);
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
            ->where('company_id', auth()->user()?->company_id)
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
            ->where('company_id', auth()->user()?->company_id)
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
            ->where('company_id', auth()->user()?->company_id)
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
        $companyId = auth()->user()?->company_id;
        $reservation = Reservation::query()->where('company_id', $companyId)->find($reservationId);
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

        $table = Table::query()->where('company_id', $companyId)->find($reservation->table_id);
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
        $reservation = Reservation::query()->where('company_id', auth()->user()?->company_id)->find($reservationId);
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
        $item = $this->activeOrder?->items()->find($itemId);
        if (! $item) return;
        $clean = trim($tab);
        if ($clean === '') return;
        // Solo aceptar A-Z, 1-9 mayúsculas para mantenerlo simple
        $clean = strtoupper(substr($clean, 0, 5));
        app(RestaurantOrderEngine::class)->setItemTab($item, $clean);
    }

    public function unassignItemTab(int $itemId): void
    {
        $item = $this->activeOrder?->items()->find($itemId);
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
        $totalPromosDiscount = (float) $this->promotionsDiscountAmount;
        $totalGiftCardsApplied = (float) array_sum(array_map(fn ($g) => (float) $g['amount'], $this->appliedGiftCards));

        if ($this->splitMode === 'none') {
            $taxSum = (float) $items->sum('tax_amount');
            $totalSum = (float) $items->sum('total');
            $tipShare = $orderTip;
            $promoDiscount = $totalPromosDiscount;
            $giftCardsCovered = $totalGiftCardsApplied;
            // Payable = total - promos - gift cards (lo que cubre con efectivo/tarjeta)
            $afterPromos = max(0, $totalSum - $promoDiscount);
            $payable = max(0, $afterPromos - $giftCardsCovered);
            return [[
                'key' => 'main',
                'label' => null,
                'items' => $items->values(),
                'subtotal' => $orderSubtotal,
                'tax' => $taxSum,
                'tip_share' => $tipShare,
                'invoice_total' => $totalSum,                   // total bruto antes de promos
                'promo_discount' => $promoDiscount,             // descuento por promos del tab
                'gift_cards_covered' => $giftCardsCovered,      // monto cubierto por gift cards
                'payable_amount' => $payable,                   // lo que el cajero debe cubrir en pagos (sin propina)
                'grand_total' => $payable + $tipShare,          // con propina
            ]];
        }

        // by_item: prorrateo de descuento de promos por invoice_total del tab
        $grouped = $items->groupBy(fn ($i) => $i->split_tab ?: '—');
        $tabs = [];
        $tabTotals = []; // para prorratear despues
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
                'unassigned' => $label === '—',
            ];
            $tabTotals[] = $total;
        }
        // Prorratear descuento total de promociones + gift cards entre tabs
        $totalSumAll = (float) array_sum($tabTotals);
        $distributedPromos = 0.0;
        $distributedGcs = 0.0;
        $lastIdx = array_key_last($tabs);
        foreach ($tabs as $idx => &$tab) {
            // Promociones
            if ($totalPromosDiscount <= 0 || $totalSumAll <= 0) {
                $tab['promo_discount'] = 0;
            } elseif ($idx === $lastIdx) {
                $tab['promo_discount'] = round(min($totalPromosDiscount, $totalSumAll) - $distributedPromos, 2);
            } else {
                $share = round($totalPromosDiscount * ($tab['invoice_total'] / $totalSumAll), 2);
                $tab['promo_discount'] = $share;
                $distributedPromos += $share;
            }

            // Gift cards (se prorratean sobre el total post-promos para que
            // no resten mas de lo que el tab debe pagar)
            $afterPromos = max(0, $tab['invoice_total'] - $tab['promo_discount']);
            if ($totalGiftCardsApplied <= 0 || $totalSumAll <= 0) {
                $tab['gift_cards_covered'] = 0;
            } elseif ($idx === $lastIdx) {
                $tab['gift_cards_covered'] = round(min($totalGiftCardsApplied, $totalSumAll - $distributedPromos) - $distributedGcs, 2);
            } else {
                $share = round($totalGiftCardsApplied * ($tab['invoice_total'] / $totalSumAll), 2);
                // Nunca exceder lo pagable del tab (despues de promos)
                $share = min($share, $afterPromos);
                $tab['gift_cards_covered'] = $share;
                $distributedGcs += $share;
            }

            $tab['payable_amount'] = max(0, $afterPromos - $tab['gift_cards_covered']);
            $tab['grand_total'] = $tab['payable_amount'] + $tab['tip_share'];
        }
        unset($tab);
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
            ->where('company_id', auth()->user()?->company_id)
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
        $companyId = auth()->user()?->company_id;

        // 1. Si el metodo tiene PaymentMethod configurado, usar su account_id
        if ($method) {
            $configured = PaymentMethod::query()
                ->where('company_id', $companyId)
                ->where('code', $method)
                ->where('active', true)
                ->value('account_id');
            if ($configured) return (int) $configured;
        }

        // 2. Fallback: caja general o primera cuenta disponible
        return Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', 'like', '110505%')
            ->value('id') ?? Account::query()
                ->where('company_id', $companyId)
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

        // Inicializar pagos: un solo pago en efectivo por payable_amount
        // (invoice_total menos descuento de promociones del tab)
        $this->billingPayments = [];
        foreach ($tabs as $t) {
            $payable = (float) ($t['payable_amount'] ?? $t['invoice_total']);
            $this->billingPayments[$t['key']] = [[
                'method' => 'cash',
                'account_id' => $defaultAccount,
                'amount' => number_format($payable, 2, '.', ''),
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
        $target = (float) ($tab['payable_amount'] ?? $tab['invoice_total']);
        $remaining = max(0, round($target - $current, 2));

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

        // Distribuir el descuento por promociones entre los tabs ANTES de
        // validar los pagos: el cajero debe cubrir el invoice_total MENOS
        // su share del descuento.
        $promoShares = $this->distributePromotionsAcrossTabs($tabs);

        // Distribuir las gift cards aplicadas entre tabs (igual logica
        // proporcional). Devuelve [tabKey => [['gift_card_id', 'code', 'amount'], ...]]
        $giftCardSharesByTab = $this->distributeGiftCardsAcrossTabs($tabs, $promoShares);

        // Validar cada pago por tab: metodo, cuenta, monto > 0 y suma =
        // invoice_total (descontado el share de promociones)
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
            $extraDiscount = (float) ($promoShares[$t['key']] ?? 0);
            $giftCardCovered = (float) array_sum(array_map(
                fn ($g) => (float) $g['amount'],
                $giftCardSharesByTab[$t['key']] ?? [],
            ));
            // Pagos tradicionales deben cubrir: invoice_total - promos - gift cards
            $target = max(0, (float) $t['invoice_total'] - $extraDiscount - $giftCardCovered);
            $diff = round($target - $sum, 2);
            if (abs($diff) > 0.01) {
                $tabName = $t['label'] ?: 'principal';
                $hintParts = [];
                if ($extraDiscount > 0) $hintParts[] = '$' . number_format($extraDiscount, 0, ',', '.') . ' promos';
                if ($giftCardCovered > 0) $hintParts[] = '$' . number_format($giftCardCovered, 0, ',', '.') . ' gift cards';
                $hint = ! empty($hintParts)
                    ? ' (descontando ' . implode(' + ', $hintParts) . ')'
                    : '';
                Notification::make()
                    ->title('Pagos no cuadran')
                    ->body("Tab {$tabName}: faltan o sobran $".number_format(abs($diff), 0, ',', '.')." (objetivo: $".number_format($target, 0, ',', '.')."{$hint})")
                    ->danger()->send();
                return;
            }
        }

        $thirdPartyId = $this->ensureDefaultCustomer()->id;

        // Construir payload con pagos + extra_discount + gift_card_payments por tab
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
                // Descuento por promociones distribuido proporcionalmente
                // al invoice_total de este tab. RestaurantOrderEngine::bill()
                // lo distribuye entre las lineas del tab al crear la factura.
                'extra_discount' => (float) ($promoShares[$t['key']] ?? 0),
                // Gift cards aplicadas a este tab (cada una con monto a cubrir).
                // bill() las agregara como addPayment con method='gift_card'.
                'gift_card_payments' => $giftCardSharesByTab[$t['key']] ?? [],
            ];
        }

        try {
            $invoices = app(RestaurantOrderEngine::class)->bill($order, $payload, $thirdPartyId, $this->billingInvoiceKind);

            // 1. REDIMIR gift cards aplicadas — descuenta saldo y registra
            //    transaccion en el ledger. bill() ya creo el Payment con
            //    method='gift_card', aqui consolidamos la mutacion del modelo.
            $gcEngine = app(\App\Services\GiftCards\GiftCardEngine::class);
            $firstInvoice = $invoices[0] ?? null;
            foreach ($this->appliedGiftCards as $applied) {
                $card = \App\Models\GiftCard::query()->where('company_id', auth()->user()?->company_id)->find($applied['gift_card_id']);
                if (! $card) continue;
                $amount = (float) $applied['amount'];
                if ($amount <= 0) continue;
                try {
                    $gcEngine->redeem($card, $amount, $firstInvoice?->id, Auth::id());
                } catch (\Throwable $e) {
                    // Log y seguir — la factura ya esta creada
                    \Log::warning('No se pudo redimir gift card despues de billar', [
                        'card_code' => $card->code,
                        'amount' => $amount,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 2. EMITIR nuevas gift cards por cada OrderItem con metadata
            //    '_gift_card_emission' (los que el cajero agrego via modal)
            $issuedGiftCards = [];
            foreach ($order->fresh('items')->items as $item) {
                $notes = $item->notes;
                if (! $notes) continue;
                $parsed = is_string($notes) ? json_decode($notes, true) : $notes;
                if (! is_array($parsed) || empty($parsed['_gift_card_emission'])) continue;

                $meta = $parsed['_gift_card_emission'];
                try {
                    $newCard = $gcEngine->issue(
                        initialBalance: (float) $meta['amount'],
                        userId: Auth::id(),
                        saleInvoiceId: $firstInvoice?->id,
                        meta: [
                            'recipient_name' => $meta['recipient_name'] ?? null,
                            'recipient_email' => $meta['recipient_email'] ?? null,
                            'sender_name' => $meta['sender_name'] ?? null,
                        ],
                    );
                    $issuedGiftCards[] = $newCard;
                } catch (\Throwable $e) {
                    \Log::warning('No se pudo emitir gift card despues de billar', [
                        'order_id' => $order->id,
                        'amount' => $meta['amount'] ?? 0,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 3. Registrar uso de promociones (reportes + max_uses_per_customer)
            if (! empty($this->appliedPromotions) && $firstInvoice) {
                $promoEngine = app(\App\Services\Promotions\PromotionEngine::class);
                $result = new \App\Services\Promotions\PromotionResult(
                    $this->buildOrderCartContext($order, $this->couponCode),
                );
                foreach ($this->appliedPromotions as $a) {
                    $p = \App\Models\Promotion::query()->where('company_id', auth()->user()?->company_id)->find($a['promotion_id']);
                    if ($p) $result->registerApplied($p, (float) $a['discount']);
                }
                $promoEngine->recordUsages($result, $firstInvoice->id, Auth::id());
            }

            // Notificacion: con codigos de gift cards emitidas si las hay
            $numbers = implode(', ', array_map(fn ($i) => $i->fullNumber(), $invoices));
            $body = count($invoices).' factura(s) generada(s): '.$numbers;
            if (! empty($issuedGiftCards)) {
                $body .= "\n\n🎁 Gift Cards emitidas:";
                foreach ($issuedGiftCards as $gc) {
                    $body .= sprintf(
                        "\n  • %s · $%s",
                        $gc->code,
                        number_format((float) $gc->initial_balance, 0, ',', '.'),
                    );
                }
                $body .= "\n\nEntrega estos codigos al cliente.";
            }

            $notif = Notification::make()
                ->title('Cuenta cobrada')
                ->body($body)
                ->success();
            if (! empty($issuedGiftCards)) {
                $notif->persistent();
            }
            $notif->send();

            $this->flushBrowserPrintJobs();
            $this->billingModalOpen = false;
            $this->closeOrderPanel();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al cobrar')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Distribuye las gift cards aplicadas entre los tabs proporcionalmente
     * al payable_amount (despues de descontar promociones). Devuelve
     * [tabKey => [['gift_card_id', 'code', 'amount'], ...]].
     *
     * Si solo hay un tab: todas las gift cards van al unico tab.
     * Si hay multiples tabs: por cada gift card, se distribuye su amount
     * proporcionalmente al payable de cada tab. Esto puede llevar a
     * fracciones pequeñas pero cuadra al final.
     */
    protected function distributeGiftCardsAcrossTabs(array $tabs, array $promoShares): array
    {
        $byTab = [];
        if (empty($this->appliedGiftCards) || empty($tabs)) return $byTab;

        // Calcular payable post-promos de cada tab
        $payables = [];
        $totalPayable = 0.0;
        foreach ($tabs as $t) {
            $promo = (float) ($promoShares[$t['key']] ?? 0);
            $payable = max(0, (float) $t['invoice_total'] - $promo);
            $payables[$t['key']] = $payable;
            $totalPayable += $payable;
        }
        if ($totalPayable <= 0) return $byTab;

        // Caso simple: 1 tab — todas las gift cards van ahi
        if (count($tabs) === 1) {
            $key = $tabs[0]['key'];
            $byTab[$key] = array_map(fn ($g) => [
                'gift_card_id' => $g['gift_card_id'],
                'code' => $g['code'],
                'amount' => round((float) $g['amount'], 2),
            ], $this->appliedGiftCards);
            return $byTab;
        }

        // Multi-tab: por cada gift card, prorratear su amount entre tabs
        foreach ($this->appliedGiftCards as $g) {
            $gcAmount = (float) $g['amount'];
            $distributed = 0.0;
            $lastKey = array_key_last($payables);
            foreach ($payables as $tabKey => $tabPayable) {
                if ($tabKey === $lastKey) {
                    $share = round($gcAmount - $distributed, 2);
                } else {
                    $share = round($gcAmount * ($tabPayable / $totalPayable), 2);
                    $distributed += $share;
                }
                if ($share <= 0) continue;
                $byTab[$tabKey][] = [
                    'gift_card_id' => $g['gift_card_id'],
                    'code' => $g['code'],
                    'amount' => $share,
                ];
            }
        }
        return $byTab;
    }

    public function increaseQty(int $itemId): void
    {
        $item = $this->activeOrder?->items()->find($itemId);
        if (! $item) return;
        try {
            app(RestaurantOrderEngine::class)->updateItemQuantity($item, (float) $item->quantity + 1);
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
        }
    }

    public function decreaseQty(int $itemId): void
    {
        $item = $this->activeOrder?->items()->find($itemId);
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
        $item = $this->activeOrder?->items()->find($itemId);
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
            $label = $course
                ? (OrderItem::COURSES[$course] ?? 'Curso '.$course)
                : 'Todo lo pendiente';

            // Las comandas que no tienen impresora se imprimen por el
            // navegador: se abre una ventana por cada una.
            $sinImpresora = collect($tickets)->whereNull('restaurant_printer_id');
            $conImpresora = count($tickets) - $sinImpresora->count();

            $detalle = [];
            if ($conImpresora > 0) {
                $detalle[] = "{$conImpresora} ticket(s) enviado(s) a impresora.";
            }
            if ($sinImpresora->isNotEmpty()) {
                $detalle[] = $sinImpresora->count().' comanda(s) se abren para imprimir por el navegador.';
            }

            Notification::make()
                ->title("Comanda enviada · {$label}")
                ->body(implode(' ', $detalle) ?: 'Ítems marcados como enviados.')
                ->success()
                ->send();

            if ($sinImpresora->isNotEmpty()) {
                $this->dispatch('kot-print-browser', ticketIds: $sinImpresora->pluck('id')->all());
            }

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

    // ================================================================
    // PROMOCIONES — aplicar descuentos al orden activo
    // ================================================================

    /**
     * Reevalua promociones contra los items de la orden activa.
     * Se llama automaticamente cuando se renderiza la UI del panel de
     * orden (via lifecycle hook hydrate o invocando explicitamente).
     * Actualiza appliedPromotions[] y promotionsDiscountAmount.
     */
    public function evaluatePromotions(): void
    {
        if (! \App\Support\PromotionsSettings::moduleActive()) {
            $this->appliedPromotions = [];
            $this->promotionsDiscountAmount = 0.0;
            return;
        }

        $order = $this->activeOrder;
        if (! $order) {
            $this->appliedPromotions = [];
            $this->promotionsDiscountAmount = 0.0;
            return;
        }

        $context = $this->buildOrderCartContext($order, $this->couponCode);
        /** @var \App\Services\Promotions\PromotionEngine $engine */
        $engine = app(\App\Services\Promotions\PromotionEngine::class);
        $result = $engine->evaluate($context);

        $this->promotionsDiscountAmount = $result->totalDiscount();
        $this->appliedPromotions = array_map(fn ($a) => [
            'promotion_id' => $a['promotion']->id,
            'name' => $a['promotion']->name,
            'code' => $a['promotion']->code,
            'discount' => $a['discount'],
        ], $result->appliedPromotions);
    }

    public function applyCoupon(): void
    {
        $code = trim($this->couponCode);
        if ($code === '') {
            Notification::make()->title('Ingresa un código de cupón')->warning()->send();
            return;
        }

        $this->couponCode = $code;
        $previousApplied = collect($this->appliedPromotions)->pluck('promotion_id')->all();
        $this->evaluatePromotions();
        $nowApplied = collect($this->appliedPromotions)->pluck('promotion_id')->all();

        $newlyApplied = array_diff($nowApplied, $previousApplied);
        if (empty($newlyApplied)) {
            $this->couponCode = '';
            $this->evaluatePromotions();
            Notification::make()
                ->title('Cupón no aplicable')
                ->body("El código '{$code}' no existe, está vencido, no cumple las condiciones o ya alcanzó su límite.")
                ->danger()
                ->send();
            return;
        }

        Notification::make()
            ->title('Cupón aplicado')
            ->body('Descuento total: $' . number_format($this->promotionsDiscountAmount, 0, ',', '.'))
            ->success()
            ->send();
    }

    public function removeCoupon(): void
    {
        $this->couponCode = '';
        $this->evaluatePromotions();
        Notification::make()->title('Cupón removido')->success()->send();
    }

    /**
     * Construye un CartContext a partir de la orden activa para pasar al
     * PromotionEngine. Modo servicio: dine_in (mesa), takeaway o delivery.
     */
    protected function buildOrderCartContext(\App\Models\Restaurant\Order $order, ?string $couponCode = null): \App\Services\Promotions\CartContext
    {
        $order->loadMissing(['items.product:id,category_id,code']);

        $lines = [];
        foreach ($order->items as $idx => $item) {
            if ($item->kitchen_status === \App\Models\Restaurant\OrderItem::KS_CANCELLED) {
                continue;
            }
            $productId = (int) $item->product_id;
            if ($productId <= 0) continue;

            $product = $item->product;
            $lines[$idx] = new \App\Services\Promotions\CartLine(
                productId: $productId,
                categoryId: $product?->category_id,
                quantity: (int) $item->quantity,
                // unit_price efectivo: subtotal/qty (incluye modificadores)
                unitPrice: (float) $item->quantity > 0
                    ? round((float) $item->subtotal / (float) $item->quantity, 2)
                    : 0,
                reference: (string) $item->id,
            );
        }

        $serviceMode = match (true) {
            $order->is_delivery ?? false => 'delivery',
            $order->is_takeaway ?? false => 'takeaway',
            default => 'dine_in',
        };

        return new \App\Services\Promotions\CartContext(
            lines: $lines,
            customerId: null, // Restaurant no asocia cliente a la orden por default
            serviceMode: $serviceMode,
            couponCode: $couponCode,
        );
    }

    /**
     * Distribuye proporcionalmente promotionsDiscountAmount entre los tabs
     * del cobro segun su invoice_total. Devuelve [tabKey => share].
     */
    protected function distributePromotionsAcrossTabs(array $tabs): array
    {
        $totalDiscount = (float) $this->promotionsDiscountAmount;
        if ($totalDiscount <= 0 || empty($tabs)) return [];

        $totalAllTabs = (float) array_sum(array_map(fn ($t) => (float) $t['invoice_total'], $tabs));
        if ($totalAllTabs <= 0) return [];

        $effective = min($totalDiscount, $totalAllTabs);
        $shares = [];
        $distributed = 0.0;
        $lastKey = array_key_last($tabs);
        foreach ($tabs as $key => $tab) {
            if ($key === $lastKey) {
                $shares[$tab['key']] = round($effective - $distributed, 2);
            } else {
                $share = round($effective * ((float) $tab['invoice_total'] / $totalAllTabs), 2);
                $shares[$tab['key']] = $share;
                $distributed += $share;
            }
        }
        return $shares;
    }

    // ================================================================
    // GIFT CARDS — redimir como pago y emitir desde la orden
    // ================================================================

    /**
     * Valida el codigo y agrega la gift card a la lista de pagos. El monto
     * se calcula como min(saldo disponible, faltante por cubrir en la orden).
     */
    public function applyGiftCard(): void
    {
        if (! \App\Support\GiftCardsSettings::moduleActive()) {
            Notification::make()->title('Gift Cards no esta activo')->warning()->send();
            return;
        }

        $code = trim($this->giftCardCodeInput);
        if ($code === '') {
            Notification::make()->title('Ingresa un codigo de gift card')->warning()->send();
            return;
        }

        // Evitar duplicados
        foreach ($this->appliedGiftCards as $existing) {
            if (strcasecmp($existing['code'], $code) === 0) {
                Notification::make()->title('Gift card ya aplicada')->warning()->send();
                $this->giftCardCodeInput = '';
                return;
            }
        }

        $engine = app(\App\Services\GiftCards\GiftCardEngine::class);
        $card = $engine->findRedeemable($code);
        if (! $card) {
            Notification::make()
                ->title('Gift card no valida')
                ->body('La tarjeta no existe, esta anulada, sin saldo o expirada.')
                ->danger()
                ->send();
            return;
        }

        // Calcular cuanto cubrir: min(saldo, faltante en la orden completa)
        $tabs = $this->billingTabs;
        $totalOrderPayable = (float) array_sum(array_map(fn ($t) => (float) ($t['payable_amount'] ?? $t['invoice_total']), $tabs));
        $alreadyCovered = (float) array_sum(array_map(fn ($g) => (float) $g['amount'], $this->appliedGiftCards));
        $remaining = max(0, $totalOrderPayable - $alreadyCovered);
        $balance = (float) $card->current_balance;
        $amountToRedeem = $remaining > 0 ? min($remaining, $balance) : $balance;

        if ($amountToRedeem <= 0) {
            Notification::make()
                ->title('Nada por cubrir')
                ->body('La orden ya esta totalmente cubierta. Quita una gift card antes de agregar otra.')
                ->warning()
                ->send();
            return;
        }

        $this->appliedGiftCards[] = [
            'gift_card_id' => $card->id,
            'code' => $card->code,
            'amount' => $amountToRedeem,
            'available_balance' => $balance,
        ];

        $this->giftCardCodeInput = '';

        Notification::make()
            ->title("Gift card {$card->code} aplicada")
            ->body('Saldo: $' . number_format($balance, 0, ',', '.')
                . ' · Se redime: $' . number_format($amountToRedeem, 0, ',', '.'))
            ->success()
            ->send();
    }

    public function removeAppliedGiftCard(int $index): void
    {
        if (! isset($this->appliedGiftCards[$index])) return;
        unset($this->appliedGiftCards[$index]);
        $this->appliedGiftCards = array_values($this->appliedGiftCards);
    }

    /**
     * Confirma el modal de emision: agrega un OrderItem con el producto
     * Gift Card al precio ingresado. La metadata 'gift_card_emission' va
     * en order.metadata (o similar) — al billar, RestaurantPos detecta
     * esos items y los emite como gift cards reales tras crear la factura.
     */
    public function confirmGiftCardEmission(): void
    {
        $amount = (float) ($this->giftCardEmissionAmount ?? 0);
        if ($amount <= 0) {
            Notification::make()->title('Ingresa un monto valido')->danger()->send();
            return;
        }

        $order = $this->activeOrder;
        if (! $order) return;

        $product = \App\Services\GiftCards\GiftCardProductProvisioner::find(Auth::user()->company_id);
        if (! $product) {
            Notification::make()->title('Producto "Tarjeta Regalo" no encontrado. Activa el modulo en Configuraciones.')->danger()->send();
            return;
        }

        // Crear el OrderItem manualmente con notes que llevan la metadata
        // de emision. RestaurantOrderEngine no conoce este caso especial,
        // asi que vamos directo al modelo + dejamos metadata para confirmBilling.
        try {
            $item = $order->items()->create([
                'company_id' => $order->company_id,
                'product_id' => $product->id,
                'description' => 'Tarjeta Regalo $' . number_format($amount, 0, ',', '.'),
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_id' => null,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'subtotal' => $amount,
                'total' => $amount,
                'modifier_total' => 0,
                'course' => 1,
                'kitchen_status' => \App\Models\Restaurant\OrderItem::KS_SERVED,
                'sent_to_kitchen_at' => now(),
                'notes' => json_encode([
                    '_gift_card_emission' => [
                        'amount' => $amount,
                        'recipient_name' => $this->giftCardEmissionRecipientName ?: null,
                        'recipient_email' => $this->giftCardEmissionRecipientEmail ?: null,
                        'sender_name' => $this->giftCardEmissionSenderName ?: null,
                    ],
                ]),
                'created_by_user_id' => Auth::id(),
            ]);

            // Reset modal
            $this->showGiftCardEmissionModal = false;
            $this->giftCardEmissionAmount = null;
            $this->giftCardEmissionRecipientName = '';
            $this->giftCardEmissionRecipientEmail = '';
            $this->giftCardEmissionSenderName = '';

            // Refresh order para que el panel muestre el nuevo item
            unset($this->activeOrder);

            Notification::make()
                ->title('Gift card agregada a la orden')
                ->body('Al cobrar se emitira el codigo y se entregara al cliente.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al agregar')->body($e->getMessage())->danger()->send();
        }
    }

    public function closeGiftCardEmissionModal(): void
    {
        $this->showGiftCardEmissionModal = false;
        $this->giftCardEmissionAmount = null;
    }
}
