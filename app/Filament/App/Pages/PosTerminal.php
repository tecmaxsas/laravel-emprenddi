<?php

namespace App\Filament\App\Pages;

use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\Account;
use App\Models\Company;
use App\Services\Sales\SaleInvoiceEngine;
use App\Services\Sales\SaleInvoiceNumberer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Terminal POS — venta rápida B2C.
 *
 * No usa Filament Forms: el carrito es estado Livewire puro para que la edición
 * de líneas sea instantánea y no haya roundtrips por cada keystroke en cantidad
 * o precio. El procesamiento final (creación + posting + payments) sí usa el
 * SaleInvoiceEngine para garantizar la misma lógica contable que ventas normales.
 */
class PosTerminal extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationLabel = 'POS — Punto de Venta';

    protected static ?string $title = 'Terminal POS';

    protected static ?string $slug = 'pos';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Ventas';

    protected static string $view = 'filament.app.pages.pos-terminal';

    // Cabecera
    public ?int $location_id = null;
    public ?int $customer_id = null;
    public ?int $seller_user_id = null;

    // Búsqueda de productos
    public string $productSearch = '';
    public array $searchResults = [];

    // Carrito: array de líneas
    // ['product_id', 'code', 'description', 'quantity', 'unit_price',
    //  'discount_percentage', 'discount_amount', 'tax_id', 'tax_rate',
    //  'tax_amount', 'subtotal', 'total']
    public array $cart = [];

    // Pagos: array de líneas
    // ['payment_method', 'account_id', 'amount', 'reference']
    public array $payments = [];

    // UI state
    public bool $showCustomerModal = false;
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

    /**
     * Crea/recupera "Consumidor Final" — el cliente fallback del POS cuando
     * el cajero no captura los datos del cliente real.
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

    // ================================================================
    // BÚSQUEDA DE PRODUCTOS
    // ================================================================

    public function updatedProductSearch(): void
    {
        $term = trim($this->productSearch);

        if (strlen($term) < 2) {
            $this->searchResults = [];
            return;
        }

        $this->searchResults = Product::query()
            ->where('active', true)
            ->where('is_sellable', true)
            ->where('type', '!=', 'variable')
            ->where(function ($q) use ($term) {
                $q->where('code', 'ilike', "%{$term}%")
                  ->orWhere('name', 'ilike', "%{$term}%")
                  ->orWhere('barcode', 'ilike', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'code', 'name', 'barcode', 'default_sale_price', 'default_sale_tax_id'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'price' => (float) $p->default_sale_price,
            ])
            ->all();
    }

    public function addProductToCart(int $productId): void
    {
        $product = Product::find($productId);
        if (! $product || ! $product->is_sellable) {
            return;
        }

        // Si ya está en carrito, incrementa cantidad en vez de duplicar línea
        foreach ($this->cart as $i => $line) {
            if ($line['product_id'] === $productId) {
                $this->cart[$i]['quantity']++;
                $this->recomputeLine($i);
                $this->productSearch = '';
                $this->searchResults = [];
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

        $this->productSearch = '';
        $this->searchResults = [];
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
        // Cuando Livewire sincroniza cualquier campo del carrito, recomputamos todo
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
    // PAGOS
    // ================================================================

    public function quickPay(string $method): void
    {
        $totals = $this->totals();
        $remaining = max(0, $totals['total'] - $totals['paid']);

        $accountId = $this->defaultAccountForMethod($method);

        $this->payments[] = [
            'payment_method' => $method,
            'account_id' => $accountId,
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
        // Default: Caja general (110505) para efectivo, Bancos (1110) para resto.
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
        ];
    }

    // ================================================================
    // CLIENTE (quick-create)
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
    // PROCESAR VENTA
    // ================================================================

    public function processSale(): void
    {
        if (empty($this->cart)) {
            Notification::make()->title('Carrito vacío')->danger()->send();
            return;
        }
        if (! $this->location_id) {
            Notification::make()->title('Selecciona una sede antes de procesar')->danger()->send();
            return;
        }
        if (! $this->customer_id) {
            Notification::make()->title('Selecciona un cliente')->danger()->send();
            return;
        }

        $totals = $this->totals();
        if ($totals['paid'] + 0.01 < $totals['total']) {
            Notification::make()
                ->title('Pagos insuficientes')
                ->body('Falta cubrir $'.number_format($totals['remaining'], 2).'.')
                ->danger()
                ->send();
            return;
        }

        try {
            $invoice = DB::transaction(function () {
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

                // Líneas
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

                // Postear (asiento + inventario + reserva consecutivo DIAN si aplica)
                $engine = app(SaleInvoiceEngine::class);
                $invoice = $engine->post($invoice->fresh('lines'));

                // Registrar cada pago — el engine actualiza paid_amount + payment_status
                foreach ($this->payments as $payment) {
                    $amount = (float) $payment['amount'];
                    if ($amount <= 0) continue;

                    // Si el pago es mayor al saldo pendiente, ajustamos al saldo
                    // (caso de exceso en efectivo: el "vuelto" se devuelve al cliente,
                    // no se asienta como pago).
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

            $totals = $this->totals();
            Notification::make()
                ->title('Venta procesada — '.$invoice->fullNumber())
                ->body(sprintf(
                    'Total $%s. Vuelto: $%s.',
                    number_format($invoice->total, 2),
                    number_format($totals['change'], 2),
                ))
                ->success()
                ->send();

            $this->resetCart();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al procesar la venta')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function resetCart(): void
    {
        $this->cart = [];
        $this->payments = [];
        $this->productSearch = '';
        $this->searchResults = [];
        // Mantenemos location_id, customer_id (default Consumidor Final), seller_user_id
        $this->customer_id = $this->ensureDefaultCustomer()->id;
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

    public function getQuickPayMethodsProperty(): array
    {
        // Los 4 métodos más usados como botones rápidos
        return [
            'cash' => 'Efectivo',
            'debit_card' => 'Débito',
            'credit_card' => 'Crédito',
            'bank_transfer' => 'Transferencia',
        ];
    }
}
