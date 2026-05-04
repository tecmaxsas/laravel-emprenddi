<?php

namespace App\Filament\App\Pages;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Services\Sales\SaleInvoiceEngine;
use App\Services\Sales\SaleInvoiceNumberer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    // Filtro de productos
    public ?int $selectedCategoryId = null;
    public string $productSearch = '';

    // Carrito
    public array $cart = [];

    // Pagos
    public array $payments = [];

    // UI state
    public bool $showCustomerModal = false;
    public bool $showPaymentModal = false;
    public string $paymentMode = 'multi'; // multi | cash | card | transfer | credit
    public string $newCustomerName = '';
    public string $newCustomerDocument = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('pos.use');
    }

    public function mount(): void
    {
        $this->seller_user_id = Auth::id();
        $this->location_id = Location::query()
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->value('id');

        $this->customer_id = $this->ensureDefaultCustomer()->id;
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
            ->get(['id', 'code', 'name', 'barcode', 'image_path', 'default_sale_price', 'default_sale_tax_id']);
    }

    public function selectCategory(?int $id): void
    {
        $this->selectedCategoryId = $id;
        $this->productSearch = '';
    }

    public function addProductToCart(int $productId): void
    {
        $product = Product::find($productId);
        if (! $product || ! $product->is_sellable) {
            return;
        }

        // Si ya está en carrito, suma cantidad
        foreach ($this->cart as $i => $line) {
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

        $this->cart[] = [
            'product_id' => $product->id,
            'code' => $product->code,
            'description' => $product->name,
            'quantity' => 1.0,
            'unit_price' => (float) $product->default_sale_price,
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
        $this->cart[$i]['quantity']++;
        $this->recomputeLine($i);
    }

    public function decLine(int $i): void
    {
        if (! isset($this->cart[$i])) return;
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
        $discountPct = (float) ($line['discount_percentage'] ?? 0);

        $subtotal = round($qty * $unitPrice, 2);
        $discountAmount = round($subtotal * ($discountPct / 100), 2);
        $taxable = $subtotal - $discountAmount;

        $taxRate = (float) ($line['tax_rate'] ?? 0);
        $taxAmount = round($taxable * ($taxRate / 100), 2);

        $line['subtotal'] = $subtotal;
        $line['discount_amount'] = $discountAmount;
        $line['tax_amount'] = $taxAmount;
        $line['total'] = $taxable + $taxAmount;
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

        // Pre-llena pagos según el modo
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
                    'amount' => $totals['total'],
                    'reference' => '',
                ];
            }
        }

        $this->showPaymentModal = true;
    }

    public function addPaymentLine(): void
    {
        $totals = $this->totals();
        $remaining = max(0, $totals['total'] - $totals['paid']);

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

    protected function defaultAccountForMethod(string $method): ?int
    {
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

        $paid = collect($this->payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0));
        $change = max(0, $paid - $total);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'paid' => $paid,
            'change' => $change,
            'remaining' => max(0, $total - $paid),
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
        if (empty($this->cart)) {
            Notification::make()->title('Carrito vacío')->danger()->send();
            return;
        }
        if (! $this->location_id || ! $this->customer_id) {
            Notification::make()->title('Faltan sede o cliente')->danger()->send();
            return;
        }

        $totals = $this->totals();

        // En venta a crédito (paymentMode='credit'), no exigimos pagos.
        if ($this->paymentMode !== 'credit' && $totals['paid'] + 0.01 < $totals['total']) {
            Notification::make()
                ->title('Pagos insuficientes')
                ->body('Falta cubrir $'.number_format($totals['remaining'], 2).'.')
                ->danger()
                ->send();
            return;
        }

        try {
            $invoice = DB::transaction(function () use ($totals) {
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
                    ]);
                }

                $engine = app(SaleInvoiceEngine::class);
                $invoice = $engine->post($invoice->fresh('lines'));

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

            $this->resetCart();
            $this->showPaymentModal = false;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al procesar la venta')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
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

    public function resetCart(): void
    {
        $this->cart = [];
        $this->payments = [];
        $this->productSearch = '';
        $this->selectedCategoryId = null;
        $this->paymentMode = 'multi';
        $this->customer_id = $this->ensureDefaultCustomer()->id;
    }

    public function closePaymentModal(): void
    {
        $this->showPaymentModal = false;
        $this->payments = [];
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

    public function getPaymentMethodsProperty(): array
    {
        return Payment::PAYMENT_METHODS;
    }
}
