<?php

namespace App\Filament\App\Pages;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SuspendedSale;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Services\Sales\SaleInvoiceEngine;
use App\Services\Sales\SaleInvoiceNumberer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

/**
 * Terminal POS — full-screen kiosko.
 *
 * UI: 3 columnas (categorías | grid de productos | carrito) + barra inferior
 * con acciones de cobro. Esconde la sidebar/topbar de Filament para que ocupe
 * toda la pantalla — el cajero opera sin distracciones.
 */
class PosTerminal extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationLabel = 'POS — Punto de Venta';

    protected static ?string $title = 'POS';

    protected static ?string $slug = 'pos';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Ventas';

    protected static string $view = 'filament.app.pages.pos-terminal';

    // Cabecera
    public ?int $location_id = null;
    public ?int $customer_id = null;
    public ?int $seller_user_id = null;
    public ?int $session_id = null;

    // Apertura de caja
    public ?int $openingLocationId = null;
    public ?float $openingAmount = 0.0;
    public string $openingNotes = '';

    // Cierre de caja
    public ?float $closingCounted = 0.0;
    public string $closingNotes = '';
    public bool $showSessionDetailsModal = false;
    public bool $showCloseSessionModal = false;

    // Filtro de productos
    public ?int $selectedCategoryId = null;
    public string $productSearch = '';

    // Carrito
    public array $cart = [];

    // Pagos
    public array $payments = [];

    // Retenciones (opt-in, B2B con Gran Contribuyente / Estado)
    // Cada item: ['tax_id', 'tax_code', 'tax_name', 'tax_type', 'base_amount', 'rate', 'amount']
    public array $retentions = [];

    // UI state
    public bool $showCustomerModal = false;
    public bool $showPaymentModal = false;
    public ?string $paymentError = null;
    public bool $showSuspendModal = false;
    public bool $showRecoverModal = false;
    public bool $showRetentionsModal = false;
    public string $paymentMode = 'multi'; // multi | cash | card | transfer | credit
    public string $invoiceKind = 'pos';   // pos | electronic — tipo de factura a emitir
    public string $newCustomerName = '';
    public string $newCustomerDocument = '';
    public string $suspendName = '';

    // Descuento global de la venta. Se distribuye sumándose proporcionalmente
    // al descuento manual de cada línea en recomputeLine() para que el IVA
    // se calcule sobre la base correcta y no requiera líneas negativas.
    public float $cartDiscountPct = 0.0;
    public float $cartDiscountAmount = 0.0;
    public string $cartDiscountMode = 'pct'; // pct | amount

    // Modal de aprobación por supervisor para descuentos que exceden umbral.
    // pendingDiscount estructura: ['type' => 'line'|'cart', 'index' => int|null,
    // 'pct' => float, 'mode' => 'pct'|'amount', 'value' => float]
    public bool $showSupervisorPinModal = false;
    public ?array $pendingDiscount = null;
    public string $supervisorPin = '';
    public ?string $supervisorPinError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('pos.use');
    }

    public function mount(): void
    {
        $this->seller_user_id = Auth::id();

        $session = $this->openSession();
        if ($session) {
            $this->session_id = $session->id;
            $this->location_id = $session->location_id;
        } else {
            // Sin sesión abierta: pre-llena la sede principal en el form de apertura
            $this->openingLocationId = Location::query()
                ->where('active', true)
                ->orderByDesc('is_main')
                ->orderBy('id')
                ->value('id');
        }

        $this->customer_id = $this->ensureDefaultCustomer()->id;
    }

    /**
     * Sesión abierta del cajero actual, si existe.
     */
    public function openSession(): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('cashier_user_id', Auth::id())
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    public function getCurrentSessionProperty(): ?CashRegisterSession
    {
        return $this->session_id ? CashRegisterSession::find($this->session_id) : null;
    }

    public function getHasOpenSessionProperty(): bool
    {
        return $this->session_id !== null;
    }

    /**
     * Settings POS de la empresa (settings.pos.*). Defaults sensatos si no
     * está configurado todavía.
     */
    public function getPosSettingsProperty(): array
    {
        $settings = \App\Models\Company::find(Auth::user()->company_id)?->settings ?? [];
        return array_merge([
            'allow_price_modification' => true,
            'allow_discount' => true,
            'allow_tax_modification' => true,
            'require_customer' => false,
            'print_after_sale' => true,
            'blind_cash_close' => false,
            'allow_negative_stock' => false,
            'default_tip_percent' => 0,
            // % a partir del cual se exige PIN de supervisor. 0 = nunca exigir.
            'discount_supervisor_threshold' => 10.0,
        ], $settings['pos'] ?? []);
    }

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

    // ================================================================
    // CATEGORÍAS Y PRODUCTOS
    // ================================================================

    public function getCategoriesProperty()
    {
        return Category::query()
            ->where('active', true)
            ->withCount(['products' => fn ($q) => $q->where('active', true)->where('is_sellable', true)])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->prepend((object) ['id' => null, 'name' => 'Todas', 'products_count' => null]);
    }

    public function getProductsProperty()
    {
        $query = Product::query()
            ->where('active', true)
            ->where('is_sellable', true)
            ->where('type', '!=', 'variable');

        if ($this->selectedCategoryId) {
            $query->where('category_id', $this->selectedCategoryId);
        }

        $term = trim($this->productSearch);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('code', 'ilike', "%{$term}%")
                  ->orWhere('name', 'ilike', "%{$term}%")
                  ->orWhere('barcode', 'ilike', "%{$term}%");
            });
        }

        return $query
            ->orderBy('name')
            ->limit(60)
            ->get(['id', 'code', 'name', 'barcode', 'image_path', 'default_sale_price', 'default_sale_tax_id', 'sale_price_includes_tax']);
    }

    public function selectCategory(?int $id): void
    {
        $this->selectedCategoryId = $id;
        $this->productSearch = '';
    }

    /**
     * Auto-add por escaneo de código de barras.
     * El cajero escanea (la pistola simula tecleo + Enter) → este método
     * busca match exacto por barcode O code, agrega al cart, y limpia.
     * Si no hay match exacto, no hace nada — el usuario sigue viendo
     * la lista de búsqueda por similitud que ya tiene.
     */
    /**
     * Listener para hotkey F9 — abre el modal de cliente desde JS.
     */
    #[On('open-customer-modal')]
    public function handleOpenCustomerModal(): void
    {
        $this->showCustomerModal = true;
    }

    public function addByBarcode(string $code): void
    {
        $code = trim($code);
        if ($code === '') return;

        // 1. Si la feature de seriales está activa, primero probar si el
        // código pistoleado es un serial in_stock. Esto permite que el cajero
        // tenga UN solo input para barcode y serial — el sistema discrimina.
        if (\App\Support\SerialsSettings::enabled()) {
            $serial = \App\Models\ProductSerial::query()
                ->where('serial_number', $code)
                ->where('status', \App\Models\ProductSerial::STATUS_IN_STOCK)
                ->with('product')
                ->first();

            if ($serial) {
                // No permitir vender el mismo serial dos veces en la misma sesión
                foreach ($this->cart as $line) {
                    if (($line['serial_id'] ?? null) === $serial->id) {
                        Notification::make()
                            ->title('Serial ya está en el carrito')
                            ->body("El serial {$serial->serial_number} ya fue agregado.")
                            ->warning()
                            ->send();
                        return;
                    }
                }
                $this->addProductBySerial($serial);
                $this->productSearch = '';
                return;
            }
        }

        // 2. Fallback: búsqueda por barcode/code del producto
        $product = Product::query()
            ->where('active', true)
            ->where('is_sellable', true)
            ->where('type', '!=', 'variable')
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('code', $code);
            })
            ->first();

        if (! $product) {
            Notification::make()
                ->title('Producto no encontrado')
                ->body("Código '{$code}' no coincide con ningún producto activo ni serial en stock.")
                ->warning()
                ->send();
            return;
        }

        // 3. Si el producto encontrado MANEJA seriales, exigir scan por serial.
        // No puedes vender un equipo serializado sin saber qué unidad sale.
        if (\App\Support\SerialsSettings::enabled() && $product->tracks_serials) {
            Notification::make()
                ->title('Producto requiere número de serie')
                ->body("'{$product->name}' se vende por serial. Escanea el serial específico de la unidad que sale.")
                ->warning()
                ->send();
            return;
        }

        $this->addProductToCart($product->id);
        $this->productSearch = '';
    }

    /**
     * Agrega una unidad serializada al carrito como línea independiente
     * (qty=1 fija). El serial_id viaja en la línea para que al postear
     * el SaleInvoiceEngine pueda marcarlo como sold y vincular la garantía.
     */
    protected function addProductBySerial(\App\Models\ProductSerial $serial): void
    {
        $product = $serial->product;
        if (! $product || ! $product->is_sellable) {
            Notification::make()
                ->title('No vendible')
                ->body('Este producto no se puede vender por POS.')
                ->warning()
                ->send();
            return;
        }

        $taxRate = 0;
        if ($product->default_sale_tax_id) {
            $taxRate = (float) Tax::find($product->default_sale_tax_id)?->rate;
        }
        $rawPrice = (float) $product->default_sale_price;
        $unitPrice = ($product->sale_price_includes_tax && $taxRate > 0)
            ? round($rawPrice / (1 + $taxRate / 100), 2)
            : $rawPrice;

        $this->cart[] = [
            'product_id' => $product->id,
            'code' => $product->code,
            'description' => $product->name." · SN {$serial->serial_number}",
            'image_path' => $product->image_path,
            'quantity' => 1.0,
            'unit_price' => $unitPrice,
            'discount_percentage' => 0.0,
            'discount_amount' => 0.0,
            'tax_id' => $product->default_sale_tax_id,
            'tax_rate' => $taxRate,
            'tax_amount' => 0.0,
            'subtotal' => 0.0,
            'total' => 0.0,
            'serial_id' => $serial->id,
            'serial_number' => $serial->serial_number,
        ];

        $i = count($this->cart) - 1;
        $this->recomputeLine($i);
    }

    public function addProductToCart(int $productId): void
    {
        $product = Product::find($productId);
        if (! $product || ! $product->is_sellable) {
            return;
        }

        // Si el producto maneja seriales, NO permitir tap directo desde el
        // grid de productos — el cajero debe escanear el serial puntual.
        if (\App\Support\SerialsSettings::enabled() && $product->tracks_serials) {
            Notification::make()
                ->title('Escanea el serial')
                ->body("'{$product->name}' se vende por número de serie. Usa el lector para escanear la unidad específica.")
                ->warning()
                ->send();
            return;
        }

        // Si ya está en carrito, suma cantidad
        foreach ($this->cart as $i => $line) {
            // Una línea con serial nunca se agrega cantidad: cada serial = línea
            if (isset($line['serial_id'])) {
                continue;
            }
            if ($line['product_id'] === $productId) {
                $this->cart[$i]['quantity']++;
                $this->recomputeLine($i);
                return;
            }
        }

        $taxRate = 0;
        if ($product->default_sale_tax_id) {
            $taxRate = (float) Tax::find($product->default_sale_tax_id)?->rate;
        }

        // Si el precio del producto YA incluye el impuesto, desnormalizar a
        // base para guardar consistente con el resto del modelo contable.
        $rawPrice = (float) $product->default_sale_price;
        $unitPrice = ($product->sale_price_includes_tax && $taxRate > 0)
            ? round($rawPrice / (1 + $taxRate / 100), 2)
            : $rawPrice;

        $this->cart[] = [
            'product_id' => $product->id,
            'code' => $product->code,
            'description' => $product->name,
            'image_path' => $product->image_path,
            'quantity' => 1.0,
            'unit_price' => $unitPrice,
            'discount_percentage' => 0.0,
            'discount_amount' => 0.0,
            'tax_id' => $product->default_sale_tax_id,
            'tax_rate' => $taxRate,
            'tax_amount' => 0.0,
            'subtotal' => 0.0,
            'total' => 0.0,
        ];

        $i = count($this->cart) - 1;
        $this->recomputeLine($i);
    }

    public function incLine(int $i): void
    {
        if (! isset($this->cart[$i])) return;
        // Una línea con serial vale 1 unidad fija (es esa unidad concreta).
        if (isset($this->cart[$i]['serial_id'])) {
            Notification::make()
                ->title('Cada serial es una unidad')
                ->body('Escanea el serial de otra unidad para venderla.')
                ->warning()
                ->send();
            return;
        }
        $this->cart[$i]['quantity']++;
        $this->recomputeLine($i);
    }

    public function decLine(int $i): void
    {
        if (! isset($this->cart[$i])) return;
        // Líneas con serial sólo pueden quitarse (no decrementar bajo 1).
        if (isset($this->cart[$i]['serial_id'])) {
            $this->removeLine($i);
            return;
        }
        if ($this->cart[$i]['quantity'] <= 1) {
            $this->removeLine($i);
            return;
        }
        $this->cart[$i]['quantity']--;
        $this->recomputeLine($i);
    }

    public function removeLine(int $i): void
    {
        if (! isset($this->cart[$i])) return;
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);
    }

    public function updatedCart(): void
    {
        foreach ($this->cart as $i => $_) {
            $this->recomputeLine($i);
        }
    }

    protected function recomputeLine(int $i): void
    {
        if (! isset($this->cart[$i])) return;

        $line = &$this->cart[$i];
        $qty = (float) ($line['quantity'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $manualPct = (float) ($line['discount_percentage_manual'] ?? $line['discount_percentage'] ?? 0);

        // El descuento global se suma al manual de la línea para que el IVA
        // se calcule sobre la base correcta sin necesidad de líneas negativas.
        $effectivePct = min(100, $manualPct + $this->cartDiscountPct);

        $subtotal = round($qty * $unitPrice, 2);
        $discountAmount = round($subtotal * ($effectivePct / 100), 2);
        $taxable = $subtotal - $discountAmount;

        $taxRate = (float) ($line['tax_rate'] ?? 0);
        $taxAmount = round($taxable * ($taxRate / 100), 2);

        $line['discount_percentage_manual'] = $manualPct;
        $line['discount_percentage'] = $effectivePct;
        $line['subtotal'] = $subtotal;
        $line['discount_amount'] = $discountAmount;
        $line['tax_amount'] = $taxAmount;
        $line['total'] = $taxable + $taxAmount;
    }

    // ================================================================
    // DESCUENTOS — manual por línea + global + flujo de aprobación
    // ================================================================

    /**
     * Devuelve true si el usuario actual puede aprobar cualquier descuento
     * (skip del PIN). Lo tienen admin/manager por defecto.
     */
    public function getCanApproveDiscountsProperty(): bool
    {
        return (bool) Auth::user()?->can('pos.discount.approve');
    }

    /**
     * Umbral % a partir del cual se exige PIN supervisor.
     * 0 = nunca; permitir todo descuento sin aprobación.
     */
    protected function discountThreshold(): float
    {
        return (float) ($this->posSettings['discount_supervisor_threshold'] ?? 10);
    }

    /**
     * Descuento por línea. $pct se intercepta y si excede umbral lanza
     * el modal de PIN; el cambio sólo se aplica tras aprobación.
     */
    public function setLineDiscountPct(int $i, float $pct): void
    {
        if (! $this->posSettings['allow_discount']) {
            Notification::make()->title('Descuentos deshabilitados en este POS')->warning()->send();
            return;
        }
        if (! isset($this->cart[$i])) return;

        $pct = max(0, min(100, (float) $pct));

        if ($this->needsApproval($pct)) {
            $this->pendingDiscount = ['type' => 'line', 'index' => $i, 'pct' => $pct];
            $this->showSupervisorPinModal = true;
            $this->supervisorPin = '';
            $this->supervisorPinError = null;
            return;
        }

        $this->applyLineDiscount($i, $pct);
    }

    protected function applyLineDiscount(int $i, float $pct): void
    {
        $this->cart[$i]['discount_percentage_manual'] = $pct;
        $this->recomputeLine($i);
    }

    /**
     * Descuento global. Soporta % o monto fijo. El monto se convierte a %
     * sobre el subtotal actual para ser consistente con la línea.
     */
    public function setCartDiscount(string $mode, float $value): void
    {
        if (! $this->posSettings['allow_discount']) {
            Notification::make()->title('Descuentos deshabilitados en este POS')->warning()->send();
            return;
        }

        $value = max(0, (float) $value);
        $pct = $value;
        if ($mode === 'amount') {
            $subtotal = collect($this->cart)->sum(fn ($l) => (float) ($l['subtotal'] ?? 0));
            $pct = $subtotal > 0 ? min(100, round(($value / $subtotal) * 100, 2)) : 0;
        }
        $pct = min(100, max(0, $pct));

        if ($this->needsApproval($pct)) {
            $this->pendingDiscount = ['type' => 'cart', 'mode' => $mode, 'value' => $value, 'pct' => $pct];
            $this->showSupervisorPinModal = true;
            $this->supervisorPin = '';
            $this->supervisorPinError = null;
            return;
        }

        $this->applyCartDiscount($mode, $value, $pct);
    }

    protected function applyCartDiscount(string $mode, float $value, float $pct): void
    {
        $this->cartDiscountMode = $mode;
        $this->cartDiscountAmount = $mode === 'amount' ? $value : 0;
        $this->cartDiscountPct = $pct;
        foreach (array_keys($this->cart) as $i) {
            $this->recomputeLine($i);
        }
    }

    public function clearCartDiscount(): void
    {
        $this->applyCartDiscount('pct', 0, 0);
    }

    protected function needsApproval(float $pct): bool
    {
        $threshold = $this->discountThreshold();
        if ($threshold <= 0) return false;        // umbral=0 → nunca exigir
        if ($pct <= $threshold) return false;     // dentro del límite
        return ! $this->canApproveDiscounts;      // si user ya puede, omitir
    }

    /**
     * Valida el PIN (= contraseña) de un user con permiso pos.discount.approve
     * de la misma empresa. Si OK aplica el descuento pendiente.
     */
    public function approveDiscountWithPin(): void
    {
        $this->supervisorPinError = null;

        if (! $this->pendingDiscount) {
            $this->showSupervisorPinModal = false;
            return;
        }
        if (trim($this->supervisorPin) === '') {
            $this->supervisorPinError = 'Ingresa la contraseña del supervisor.';
            return;
        }

        $supervisors = \App\Models\User::query()
            ->where('company_id', Auth::user()->company_id)
            ->where('active', true)
            ->permission('pos.discount.approve')
            ->get();

        $ok = false;
        foreach ($supervisors as $sup) {
            if (\Illuminate\Support\Facades\Hash::check($this->supervisorPin, $sup->password)) {
                $ok = true;
                break;
            }
        }

        if (! $ok) {
            $this->supervisorPinError = 'Contraseña no coincide con ningún supervisor con permiso de aprobación.';
            $this->supervisorPin = '';
            return;
        }

        // Aplicar el descuento pendiente
        $d = $this->pendingDiscount;
        if ($d['type'] === 'line') {
            $this->applyLineDiscount($d['index'], $d['pct']);
        } else {
            $this->applyCartDiscount($d['mode'] ?? 'pct', $d['value'] ?? $d['pct'], $d['pct']);
        }

        $this->pendingDiscount = null;
        $this->supervisorPin = '';
        $this->supervisorPinError = null;
        $this->showSupervisorPinModal = false;
        Notification::make()->title('Descuento aprobado por supervisor')->success()->send();
    }

    public function cancelSupervisorPin(): void
    {
        $this->pendingDiscount = null;
        $this->supervisorPin = '';
        $this->supervisorPinError = null;
        $this->showSupervisorPinModal = false;
    }

    // ================================================================
    // PAGOS Y MODALES DE COBRO
    // ================================================================

    public function openPayment(string $mode): void
    {
        if (empty($this->cart)) {
            Notification::make()->title('Carrito vacío')->warning()->send();
            return;
        }

        $this->paymentMode = $mode;
        $totals = $this->totals();

        // Pre-llena pagos según el modo. Monto = net_payable (lo que el
        // cliente realmente paga, ya descontadas retenciones si aplica).
        $this->payments = [];
        if ($mode !== 'multi') {
            $methodMap = [
                'cash' => 'cash',
                'card' => 'credit_card',
                'transfer' => 'bank_transfer',
                'credit' => 'other', // venta a crédito = no se cobra ahora
            ];
            $method = $methodMap[$mode] ?? 'cash';
            if ($mode !== 'credit') {
                $this->payments[] = [
                    'payment_method' => $method,
                    'account_id' => $this->defaultAccountForMethod($method),
                    'amount' => $totals['net_payable'],
                    'reference' => '',
                ];
            }
        }

        $this->paymentError = null;
        $this->showPaymentModal = true;
    }

    public function addPaymentLine(): void
    {
        $totals = $this->totals();
        $remaining = max(0, $totals['net_payable'] - $totals['paid']);

        $this->payments[] = [
            'payment_method' => 'cash',
            'account_id' => $this->defaultAccountForMethod('cash'),
            'amount' => $remaining,
            'reference' => '',
        ];
    }

    public function removePayment(int $i): void
    {
        if (! isset($this->payments[$i])) return;
        unset($this->payments[$i]);
        $this->payments = array_values($this->payments);
    }

    // ================================================================
    // RETENCIONES (opt-in, B2B con cliente agente retenedor)
    // ================================================================

    public function openRetentionsModal(): void
    {
        if (empty($this->cart)) {
            Notification::make()->title('Carrito vacío')->warning()->send();
            return;
        }
        $this->showRetentionsModal = true;
    }

    public function addRetention(int $taxId): void
    {
        $tax = Tax::query()
            ->where('id', $taxId)
            ->where('is_active', true)
            ->whereIn('type', ['income_withholding', 'vat_withholding', 'ica_withholding'])
            ->whereIn('applies_to', ['sale', 'both'])
            ->first();

        if (! $tax) return;

        // Si ya existe esa retención, no duplicar
        foreach ($this->retentions as $r) {
            if ((int) ($r['tax_id'] ?? 0) === (int) $tax->id) {
                Notification::make()->title('Esa retención ya está aplicada')->warning()->send();
                return;
            }
        }

        $totals = $this->totals();
        // Base default: subtotal - descuento (base gravable estándar)
        $base = max(0, $totals['subtotal'] - $totals['discount']);
        $rate = (float) $tax->rate;
        $amount = round($base * ($rate / 100), 2);

        $this->retentions[] = [
            'tax_id' => $tax->id,
            'tax_code' => $tax->code,
            'tax_name' => $tax->name,
            'tax_type' => $tax->type,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
        ];
    }

    public function removeRetention(int $i): void
    {
        if (! isset($this->retentions[$i])) return;
        unset($this->retentions[$i]);
        $this->retentions = array_values($this->retentions);
    }

    public function updatedRetentions(): void
    {
        // Recompute amount cuando el cajero edita la base
        foreach ($this->retentions as $i => $_) {
            $base = (float) ($this->retentions[$i]['base_amount'] ?? 0);
            $rate = (float) ($this->retentions[$i]['rate'] ?? 0);
            $this->retentions[$i]['amount'] = round($base * ($rate / 100), 2);
        }
    }

    public function getAvailableRetentionTaxesProperty()
    {
        return Tax::query()
            ->where('is_active', true)
            ->whereIn('type', ['income_withholding', 'vat_withholding', 'ica_withholding'])
            ->whereIn('applies_to', ['sale', 'both'])
            ->orderBy('type')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'rate']);
    }

    /**
     * Impuestos disponibles para asignar a una línea del carrito en el POS.
     * Excluye las retenciones (esas se manejan en el modal de retenciones).
     */
    public function getAvailableLineTaxesProperty()
    {
        return Tax::query()
            ->where('is_active', true)
            ->whereIn('applies_to', ['sale', 'both'])
            ->whereNotIn('type', ['income_withholding', 'vat_withholding', 'ica_withholding'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'rate']);
    }

    /**
     * Cambia el impuesto de una línea del carrito. taxId puede ser null
     * (= sin impuesto). Recalcula la línea para refrescar tax_amount y total.
     */
    public function setLineTaxId(int $i, ?string $taxId): void
    {
        if (! $this->posSettings['allow_tax_modification']) {
            Notification::make()
                ->title('Modificación de impuestos deshabilitada')
                ->warning()
                ->send();
            return;
        }
        if (! isset($this->cart[$i])) return;

        $taxId = $taxId === '' || $taxId === null ? null : (int) $taxId;
        $rate = 0.0;
        if ($taxId) {
            $rate = (float) (Tax::query()->whereKey($taxId)->value('rate') ?? 0);
        }

        $this->cart[$i]['tax_id'] = $taxId;
        $this->cart[$i]['tax_rate'] = $rate;
        $this->recomputeLine($i);
    }

    protected function defaultAccountForMethod(string $method): ?int
    {
        // 1. PaymentMethod configurado por la empresa (si existe)
        $configured = PaymentMethod::query()
            ->where('code', $method)
            ->where('active', true)
            ->value('account_id');

        if ($configured) {
            return $configured;
        }

        // 2. Fallback heurístico: caja para efectivo, banco para todo lo demás
        $code = $method === 'cash' ? '110505' : '1110';

        return Account::query()
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', 'like', $code.'%')
            ->orderBy('code')
            ->value('id');
    }

    // ================================================================
    // TOTALES
    // ================================================================

    public function totals(): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($this->cart as $line) {
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $discount += (float) ($line['discount_amount'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);
        }

        $retentionTotal = collect($this->retentions)->sum(fn ($r) => (float) ($r['amount'] ?? 0));
        // net_payable = lo que el cliente realmente paga (total - retenciones).
        // Las retenciones NO son saldo pendiente — son anticipos de impuesto.
        $netPayable = max(0, $total - $retentionTotal);

        $paid = collect($this->payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0));
        $change = max(0, $paid - $netPayable);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'retentions' => $retentionTotal,
            'net_payable' => $netPayable,
            'paid' => $paid,
            'change' => $change,
            'remaining' => max(0, $netPayable - $paid),
            'items' => collect($this->cart)->sum(fn ($l) => (float) ($l['quantity'] ?? 0)),
        ];
    }

    // ================================================================
    // CLIENTE
    // ================================================================

    public function createQuickCustomer(): void
    {
        $name = trim($this->newCustomerName);
        $doc = trim($this->newCustomerDocument);

        if ($name === '' || $doc === '') {
            Notification::make()->title('Nombre y documento requeridos')->danger()->send();
            return;
        }

        $customer = ThirdParty::firstOrCreate(
            [
                'company_id' => Auth::user()->company_id,
                'document_number' => $doc,
            ],
            [
                'person_type' => strlen($doc) >= 9 ? 'juridica' : 'natural',
                'document_type' => strlen($doc) >= 9 ? 'nit' : 'cc',
                'name' => $name,
                'is_customer' => true,
                'is_supplier' => false,
                'active' => true,
            ],
        );

        $this->customer_id = $customer->id;
        $this->showCustomerModal = false;
        $this->newCustomerName = '';
        $this->newCustomerDocument = '';

        Notification::make()->title("Cliente {$customer->name} listo")->success()->send();
    }

    // ================================================================
    // ACCIONES PRINCIPALES (botones barra inferior)
    // ================================================================

    public function processSale(): void
    {
        $this->paymentError = null;

        if (empty($this->cart)) {
            $this->paymentError = 'Carrito vacío.';
            return;
        }
        if (! $this->location_id || ! $this->customer_id) {
            $this->paymentError = 'Faltan sede o cliente.';
            return;
        }

        // Setting "Cliente obligatorio": rechaza venta a Consumidor Final.
        if ($this->posSettings['require_customer'] ?? false) {
            $customer = ThirdParty::find($this->customer_id);
            if ($customer && $customer->document_number === '222222222') {
                $this->paymentError = 'Cliente obligatorio: la empresa no permite vender a "Consumidor Final".';
                return;
            }
        }

        $totals = $this->totals();

        // En venta a crédito (paymentMode='credit'), no exigimos pagos.
        // Comparamos contra net_payable (descontadas retenciones), no contra total.
        if ($this->paymentMode !== 'credit' && $totals['paid'] + 0.01 < $totals['net_payable']) {
            $this->paymentError = 'Pagos insuficientes. Falta cubrir $'.number_format($totals['remaining'], 2).'.';
            return;
        }

        // Reservar consecutivo de la resolución (POS o Electrónica) ANTES
        // de abrir la transacción. Si la sede no tiene resolución del tipo
        // elegido, esto lanza y abortamos con mensaje claro.
        try {
            $doc = app(\App\Services\Sales\DocumentNumberer::class)
                ->reserveForLocation((int) $this->location_id, $this->invoiceKind);
        } catch (\Throwable $e) {
            $this->paymentError = $e->getMessage();
            return;
        }

        try {
            $invoice = DB::transaction(function () use ($totals, $doc) {
                $companyId = Auth::user()->company_id;
                $company = Company::find($companyId);

                $invoice = SaleInvoice::create([
                    'company_id' => $companyId,
                    'location_id' => $this->location_id,
                    'third_party_id' => $this->customer_id,
                    'prefix' => $doc['prefix'],
                    'number' => $doc['number'],
                    'invoice_kind' => $doc['kind'],
                    'dian_resolution_id' => $doc['resolution_id'],
                    'date' => now()->toDateString(),
                    'currency' => 'COP',
                    'status' => 'draft',
                    'payment_status' => 'pendiente',
                    'created_by_user_id' => Auth::id(),
                    'seller_user_id' => $this->seller_user_id ?: Auth::id(),
                    'cash_register_session_id' => $this->session_id,
                ]);

                $lineNum = 1;
                foreach ($this->cart as $line) {
                    $invoice->lines()->create([
                        'line_number' => $lineNum++,
                        'product_id' => $line['product_id'],
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_percentage' => $line['discount_percentage'],
                        'discount_amount' => $line['discount_amount'],
                        'tax_id' => $line['tax_id'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax_amount'],
                        'subtotal' => $line['subtotal'],
                        'total' => $line['total'],
                        // Si la línea viene del scan de serial, se guarda en
                        // la columna serials para que el engine vincule el
                        // ProductSerial al postear.
                        'serials' => isset($line['serial_number'])
                            ? [$line['serial_number']]
                            : null,
                    ]);
                }

                // Retenciones (si las hay) — antes del posting para que
                // SaleInvoiceEngine.recalculateTotals las incluya en net_payable.
                foreach ($this->retentions as $ret) {
                    $invoice->retentions()->create([
                        'tax_id' => $ret['tax_id'],
                        'tax_code' => $ret['tax_code'],
                        'tax_name' => $ret['tax_name'],
                        'tax_type' => $ret['tax_type'],
                        'base_amount' => $ret['base_amount'],
                        'rate' => $ret['rate'],
                        'amount' => $ret['amount'],
                    ]);
                }

                $engine = app(SaleInvoiceEngine::class);
                $invoice = $engine->post($invoice->fresh(['lines', 'retentions']));

                foreach ($this->payments as $payment) {
                    $amount = (float) ($payment['amount'] ?? 0);
                    if ($amount <= 0) continue;

                    $balance = (float) $invoice->fresh()->balance;
                    if ($amount > $balance) {
                        $amount = $balance;
                    }
                    if ($amount <= 0) break;

                    $engine->addPayment($invoice, [
                        'amount' => $amount,
                        'payment_method' => $payment['payment_method'],
                        'account_id' => $payment['account_id'],
                        'date' => now()->toDateString(),
                        'reference' => $payment['reference'] ?? null,
                        'description' => 'POS — Venta '.$invoice->fullNumber(),
                    ]);
                }

                return $invoice->fresh();
            });

            Notification::make()
                ->title('Venta procesada — '.$invoice->fullNumber())
                ->body(sprintf(
                    'Total $%s. %s',
                    number_format($invoice->total, 2),
                    $totals['change'] > 0 ? 'Vuelto: $'.number_format($totals['change'], 2) : '',
                ))
                ->success()
                ->send();

            // Disparo de impresión: gobernado por settings.pos.print_after_sale.
            // Si está desactivado, el cajero abre el ticket manualmente desde
            // la vista de la factura.
            if ($this->posSettings['print_after_sale'] ?? true) {
                $this->dispatch('pos-print-ticket', invoiceId: $invoice->id);
            }

            $this->resetCart();
            $this->showPaymentModal = false;
        } catch (\Throwable $e) {
            // Loggeamos el detalle técnico (para soporte) y mostramos al
            // cajero un mensaje legible dentro del modal — los SQLSTATE,
            // stack traces y nombres de columna NO deben llegar al usuario.
            Log::error('POS processSale failed', [
                'company_id' => Auth::user()?->company_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'cart_lines' => count($this->cart),
                'payments' => count($this->payments),
            ]);

            $this->paymentError = $this->friendlyError($e);
            Notification::make()
                ->title('Error al procesar la venta')
                ->body($this->paymentError)
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Traduce excepciones técnicas a mensajes que el cajero pueda entender.
     * Los detalles (SQLSTATE, stack, query) van al log, no al usuario.
     */
    protected function friendlyError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($e instanceof \Illuminate\Database\QueryException) {
            // Por código SQLSTATE estándar
            if (str_contains($msg, '23505') || str_contains($msg, 'Duplicate entry') || str_contains($msg, 'unique')) {
                return 'Ese número de factura ya existe. Vuelve a intentarlo — el sistema asignará uno nuevo.';
            }
            if (str_contains($msg, '23503') || str_contains($msg, 'foreign key')) {
                return 'Faltan datos relacionados (cliente, producto o impuesto no válido). Revisa los ítems del carrito.';
            }
            if (str_contains($msg, '23502') || str_contains($msg, 'not-null')) {
                return 'Hay campos obligatorios sin completar. Revisa cliente, sede y productos del carrito.';
            }
            if (str_contains($msg, 'deadlock') || str_contains($msg, '40P01')) {
                return 'Conflicto con otra venta en proceso. Espera un momento y vuelve a intentar.';
            }
            if (str_contains($msg, 'Connection') || str_contains($msg, 'could not find driver') || str_contains($msg, 'server has gone away')) {
                return 'Se perdió la conexión con la base de datos. Reintenta en unos segundos.';
            }
            return 'Error de base de datos al guardar la venta. Reintenta — si persiste, contacta soporte.';
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $errors = collect($e->errors())->flatten()->take(3)->all();
            return implode(' · ', $errors);
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 'Un recurso necesario para la venta ya no existe (cliente, producto o sede eliminada). Recarga el POS.';
        }

        // RuntimeException / DomainException / etc. — los mensajes que lanzamos
        // nosotros desde services suelen ser ya legibles.
        if ($e instanceof \RuntimeException || $e instanceof \DomainException || $e instanceof \InvalidArgumentException) {
            return $msg ?: 'No se pudo completar la operación.';
        }

        // Fallback: limpiar prefijos técnicos comunes y truncar.
        $clean = preg_replace('/SQLSTATE\[[^\]]+\][:\s]*/i', '', $msg);
        $clean = preg_replace('/\s*\(Connection:[^)]*\)\s*$/i', '', (string) $clean);
        $clean = preg_replace('/\s*\(SQL:.*$/s', '', (string) $clean);
        $clean = trim((string) $clean);
        if ($clean === '') {
            return 'Error inesperado al procesar la venta. Reintenta o contacta soporte.';
        }
        return mb_strlen($clean) > 220 ? mb_substr($clean, 0, 220).'…' : $clean;
    }

    public function saveDraft(): void
    {
        if (empty($this->cart)) {
            Notification::make()->title('Carrito vacío')->warning()->send();
            return;
        }

        try {
            DB::transaction(function () {
                $companyId = Auth::user()->company_id;
                $company = Company::find($companyId);

                $invoice = SaleInvoice::create([
                    'company_id' => $companyId,
                    'location_id' => $this->location_id,
                    'third_party_id' => $this->customer_id,
                    'prefix' => 'POS',
                    'number' => app(SaleInvoiceNumberer::class)->next($company, 'POS'),
                    'date' => now()->toDateString(),
                    'currency' => 'COP',
                    'status' => 'draft',
                    'payment_status' => 'pendiente',
                    'created_by_user_id' => Auth::id(),
                    'seller_user_id' => $this->seller_user_id ?: Auth::id(),
                ]);

                $lineNum = 1;
                foreach ($this->cart as $line) {
                    $invoice->lines()->create([
                        'line_number' => $lineNum++,
                        'product_id' => $line['product_id'],
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_percentage' => $line['discount_percentage'],
                        'discount_amount' => $line['discount_amount'],
                        'tax_id' => $line['tax_id'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax_amount'],
                        'subtotal' => $line['subtotal'],
                        'total' => $line['total'],
                        // Si la línea viene del scan de serial, se guarda en
                        // la columna serials para que el engine vincule el
                        // ProductSerial al postear.
                        'serials' => isset($line['serial_number'])
                            ? [$line['serial_number']]
                            : null,
                    ]);
                }

                $totals = $this->totals();
                $invoice->update([
                    'subtotal' => $totals['subtotal'],
                    'discount_total' => $totals['discount'],
                    'tax_total' => $totals['tax'],
                    'total' => $totals['total'],
                    'net_payable' => $totals['total'],
                ]);
            });

            Notification::make()->title('Guardado como borrador')->success()->send();
            $this->resetCart();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al guardar borrador')->body($e->getMessage())->danger()->send();
        }
    }

    // ================================================================
    // SESIONES DE CAJA
    // ================================================================

    public function openCashSession(): void
    {
        if ($this->openSession()) {
            Notification::make()->title('Ya tienes una sesión abierta')->warning()->send();
            return;
        }

        if (! $this->openingLocationId) {
            Notification::make()->title('Selecciona la sede')->danger()->send();
            return;
        }

        $opening = max(0, (float) ($this->openingAmount ?? 0));

        $session = CashRegisterSession::create([
            'company_id' => Auth::user()->company_id,
            'location_id' => $this->openingLocationId,
            'cashier_user_id' => Auth::id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => $opening,
            'opening_notes' => trim($this->openingNotes) ?: null,
        ]);

        $this->session_id = $session->id;
        $this->location_id = $session->location_id;
        $this->openingLocationId = null;
        $this->openingAmount = 0.0;
        $this->openingNotes = '';

        Notification::make()
            ->title('Caja abierta')
            ->body('Sede '.$session->location->name.'. Apertura: $'.number_format($opening, 2))
            ->success()
            ->send();
    }

    public function openSessionDetailsModal(): void
    {
        if (($this->posSettings['blind_cash_close'] ?? false)
            && ! auth()->user()->hasAnyRole(['admin', 'manager'])) {
            Notification::make()
                ->title('Detalles ocultos')
                ->body('La empresa configuró "cierre de caja oculto" — solo verás el resumen al cerrar.')
                ->warning()
                ->send();
            return;
        }

        $this->showSessionDetailsModal = true;
    }

    public function getSessionTotalsProperty(): ?array
    {
        $session = $this->currentSession;
        if (! $session) return null;
        return app(\App\Services\Cash\CashSessionSummary::class)->compute($session);
    }

    public function openCloseSessionModal(): void
    {
        if (! $this->currentSession) {
            Notification::make()->title('No hay sesión abierta')->danger()->send();
            return;
        }

        $this->closingCounted = 0.0;
        $this->closingNotes = '';
        $this->showCloseSessionModal = true;
    }

    public function closeCashSession(): void
    {
        $session = $this->currentSession;
        if (! $session) {
            Notification::make()->title('No hay sesión abierta')->danger()->send();
            return;
        }

        if (! auth()->user()->can('pos.cash_close')) {
            Notification::make()->title('Sin permiso para cerrar caja')->danger()->send();
            return;
        }

        if (! empty($this->cart)) {
            Notification::make()
                ->title('Carrito no vacío')
                ->body('Termina o suspende la venta actual antes de cerrar caja.')
                ->danger()
                ->send();
            return;
        }

        // Summary unificado: ventas (ingresos), compras + gastos (egresos) y
        // breakdown global por método. Solo el efectivo afecta closing_expected.
        $summary = app(\App\Services\Cash\CashSessionSummary::class)->compute($session);
        $counted = (float) ($this->closingCounted ?? 0);
        $expected = $summary['expected_cash'];
        $difference = round($counted - $expected, 2);

        $session->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => Auth::id(),
            'closing_expected' => $expected,
            'closing_counted' => $counted,
            'closing_difference' => $difference,
            'total_sales' => $summary['sales']['total'],
            'invoice_count' => $summary['sales']['count'],
            'payment_breakdown' => $summary['payment_breakdown'],
            'closing_notes' => trim($this->closingNotes) ?: null,
        ]);

        // Reset POS state — fuerza re-login a la caja
        $this->session_id = null;
        $this->location_id = null;
        $this->showCloseSessionModal = false;
        $this->resetCart();

        $blindClose = (bool) ($this->posSettings['blind_cash_close'] ?? false);

        if ($blindClose) {
            Notification::make()->title('Caja cerrada')->success()->send();
        } else {
            Notification::make()
                ->title('Caja cerrada')
                ->body(sprintf(
                    'Esperado $%s · Contado $%s · Diferencia $%s',
                    number_format($expected, 2),
                    number_format($counted, 2),
                    number_format($difference, 2),
                ))
                ->{$difference === 0.0 ? 'success' : 'warning'}()
                ->send();
        }
    }

    public function resetCart(): void
    {
        $this->cart = [];
        $this->payments = [];
        $this->retentions = [];
        $this->productSearch = '';
        $this->selectedCategoryId = null;
        $this->paymentMode = 'multi';
        $this->customer_id = $this->ensureDefaultCustomer()->id;
    }

    public function closePaymentModal(): void
    {
        $this->showPaymentModal = false;
        $this->payments = [];
        $this->paymentError = null;
    }

    // ================================================================
    // VENTAS SUSPENDIDAS (parking)
    // ================================================================

    public function openSuspendModal(): void
    {
        if (empty($this->cart)) {
            Notification::make()->title('Carrito vacío')->warning()->send();
            return;
        }

        $this->suspendName = $this->customerName !== 'Consumidor Final'
            ? $this->customerName
            : 'Venta '.now()->format('H:i');

        $this->showSuspendModal = true;
    }

    public function suspendSale(): void
    {
        if (empty($this->cart)) {
            $this->showSuspendModal = false;
            return;
        }

        $totals = $this->totals();

        SuspendedSale::create([
            'company_id' => Auth::user()->company_id,
            'location_id' => $this->location_id,
            'seller_user_id' => $this->seller_user_id ?: Auth::id(),
            'third_party_id' => $this->customer_id,
            'name' => trim($this->suspendName) ?: 'Venta '.now()->format('Y-m-d H:i'),
            'cart_snapshot' => $this->cart,
            'payments_snapshot' => $this->payments,
            'total' => $totals['total'],
            'items_count' => (int) $totals['items'],
        ]);

        Notification::make()
            ->title('Venta suspendida')
            ->body('Puedes recuperarla cuando quieras.')
            ->success()
            ->send();

        $this->showSuspendModal = false;
        $this->suspendName = '';
        $this->resetCart();
    }

    public function recoverSale(int $id): void
    {
        $suspended = SuspendedSale::query()
            ->where('id', $id)
            ->where('location_id', $this->location_id)
            ->first();

        if (! $suspended) {
            Notification::make()->title('Venta no encontrada')->danger()->send();
            return;
        }

        // Si ya hay carrito, advertir
        if (! empty($this->cart)) {
            Notification::make()
                ->title('Carrito actual no vacío')
                ->body('Vacía o suspende el carrito actual antes de recuperar otra venta.')
                ->warning()
                ->send();
            return;
        }

        $this->cart = $suspended->cart_snapshot ?? [];
        $this->payments = $suspended->payments_snapshot ?? [];
        if ($suspended->third_party_id) {
            $this->customer_id = $suspended->third_party_id;
        }

        $suspended->delete();

        $this->showRecoverModal = false;
        Notification::make()->title('Venta recuperada')->success()->send();
    }

    public function deleteSuspendedSale(int $id): void
    {
        SuspendedSale::query()
            ->where('id', $id)
            ->where('location_id', $this->location_id)
            ->delete();

        Notification::make()->title('Venta suspendida eliminada')->success()->send();
    }

    public function getSuspendedSalesProperty()
    {
        return SuspendedSale::query()
            ->where('location_id', $this->location_id)
            ->with('customer:id,name', 'seller:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    // ================================================================
    // Datos auxiliares para la vista
    // ================================================================

    public function getCustomerNameProperty(): string
    {
        return $this->customer_id
            ? (ThirdParty::find($this->customer_id)?->name ?? '—')
            : '—';
    }

    public function getLocationNameProperty(): string
    {
        return $this->location_id
            ? (Location::find($this->location_id)?->fullName() ?? '—')
            : '—';
    }

    /**
     * Métodos de pago activos de la empresa, en orden de sort_order.
     * Cae al constante hardcoded si la empresa aún no tiene métodos sembrados.
     */
    public function getPaymentMethodsProperty(): array
    {
        $methods = PaymentMethod::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();

        return $methods ?: Payment::PAYMENT_METHODS;
    }

}
