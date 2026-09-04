<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Onboarding\CompanyOnboarding;
use App\Services\Sales\CustomerAdvanceService;
use App\Services\Sales\SaleInvoiceEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Demo de una perfumeria que vende a credito, para mostrar la cartera.
 *
 * IDEMPOTENTE: se puede correr varias veces. Detecta lo que ya existe por
 * codigo o documento y solo crea lo que falta. Las fechas se generan
 * relativas a now(), asi que cada corrida refresca el escenario.
 *
 *   docker exec emprenddi_app php artisan db:seed --class=PerfumeryDemoSeeder
 *
 * Los clientes estan armados para que cada uno muestre una situacion
 * distinta de la hoja de cuenta, no para llenar la pantalla:
 *
 *   - Al dia: compra y paga completo.
 *   - Con saldo: dos facturas, una abonada a medias.
 *   - Con saldo de apertura: migro del sistema anterior debiendo.
 *   - Con saldo a favor: abono mas de lo que debia.
 *   - Cupo copado: no puede comprar mas a credito hasta que abone.
 *   - Caso Broadway: el que en el otro sistema quedaba con saldo a favor Y
 *     deuda al mismo tiempo. Aqui el anticipo se aplica solo y el descuadre
 *     no se forma; sirve para mostrar la diferencia.
 */
class PerfumeryDemoSeeder extends Seeder
{
    protected Company $company;

    protected User $admin;

    protected Location $location;

    /** @var array<string, Product> */
    protected array $products = [];

    protected ?Tax $iva19 = null;

    public function run(): void
    {
        $this->command->info('💐 Sembrando demo de perfumería...');

        $this->ensureCompany();
        $this->ensureAdmin();
        $this->bootstrapCompany();
        $this->resolveLocation();
        $this->createProducts();
        $this->createCustomersAndSales();

        $this->command->newLine();
        $this->command->info('✅ Demo de perfumería lista.');
        $this->command->line("   Empresa:  {$this->company->name}");
        $this->command->line("   Admin:    {$this->admin->email}");
        $this->command->line('   Password: Demo2026!');
        $this->command->line('   Ver:      Reportes operativos → Estado de cuenta');
    }

    protected function ensureCompany(): void
    {
        $this->company = Company::firstOrCreate(
            ['nit' => '901777333'],
            [
                'name' => 'Perfumería Aroma S.A.S.',
                'legal_name' => 'Perfumería Aroma Sociedad por Acciones Simplificada',
                'document_type' => 'nit',
                'organization_type' => 'juridica',
                'dv' => '1',
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => 'Calle 53 #24-18',
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '6013334455',
                'phone_country_code' => '+57',
                'email' => 'contacto@perfumeriaaroma.co',
                'currency' => 'COP',
                'timezone' => 'America/Bogota',
                'active_modules' => ['retail', 'accounting'],
                'active' => true,
            ],
        );

        $modulos = $this->company->active_modules ?? [];
        foreach (['retail', 'accounting'] as $modulo) {
            if (! in_array($modulo, $modulos, true)) {
                $modulos[] = $modulo;
            }
        }
        $this->company->update(['active_modules' => array_values(array_unique($modulos))]);
    }

    protected function ensureAdmin(): void
    {
        $this->admin = User::firstOrCreate(
            ['email' => 'demo@perfumeriaaroma.co'],
            [
                'company_id' => $this->company->id,
                'name' => 'Admin',
                'last_name' => 'Perfumería',
                'password' => Hash::make('Demo2026!'),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        if (Schema::hasTable('roles')) {
            try {
                $this->admin->assignRole('admin');
            } catch (\Throwable) {
            }
        }

        // Los engines leen auth()->id() para saber quién hizo qué.
        auth()->login($this->admin);
    }

    protected function bootstrapCompany(): void
    {
        $resumen = app(CompanyOnboarding::class)->bootstrap($this->company);
        $this->command->line("   · Onboarding: PUC={$resumen['puc']}, impuestos={$resumen['taxes']}, sede={$resumen['location']}");
    }

    protected function resolveLocation(): void
    {
        $this->location = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->iva19 = Tax::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('rate', 19)
            ->first();
    }

    protected function createProducts(): void
    {
        $categoria = Category::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Perfumería'],
            ['active' => true],
        );

        $catalogo = [
            ['PERF-001', 'Eau de Parfum Nocturne 100ml', 'Nocturne', 185000, 95000],
            ['PERF-002', 'Eau de Toilette Brisa 75ml', 'Brisa', 128000, 62000],
            ['PERF-003', 'Perfume Ámbar Oud 50ml', 'Ámbar', 240000, 130000],
            ['PERF-004', 'Body Splash Cítrico 250ml', 'Frescos', 45000, 19000],
            ['PERF-005', 'Set Regalo Elegance (perfume + crema)', 'Elegance', 210000, 108000],
            ['PERF-006', 'Crema Corporal Vainilla 200ml', 'Elegance', 38000, 16000],
        ];

        foreach ($catalogo as [$codigo, $nombre, $marca, $venta, $compra]) {
            $this->products[$codigo] = Product::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $codigo],
                [
                    'name' => $nombre,
                    'category_id' => $categoria->id,
                    'type' => 'good',
                    'unit_of_measure' => 'und',
                    // Sin control de existencias: el demo es de cartera, y
                    // exigir una apertura de inventario para poder facturar
                    // solo agrega ruido a lo que se quiere mostrar.
                    'track_inventory' => false,
                    'is_purchasable' => true,
                    'is_sellable' => true,
                    'default_purchase_price' => $compra,
                    'default_sale_price' => $venta,
                    'sale_price_includes_tax' => true,
                    'default_sale_tax_id' => $this->iva19?->id,
                    'brand' => $marca,
                    'active' => true,
                ],
            );
        }

        $this->command->line('   · Productos: '.count($this->products));
    }

    /**
     * Cada cliente cuenta una situacion distinta de la cartera.
     */
    protected function createCustomersAndSales(): void
    {
        // --- Al día: compró y pagó completo -----------------------------
        $alDia = $this->customer('1018445720', 'LAURA MEJÍA CASTRO', [
            'credit_limit' => 500000,
            'email' => 'laura.mejia@example.co',
        ]);
        $f1 = $this->invoice($alDia, 20, [['PERF-002', 1], ['PERF-004', 2]]);
        $this->pay($f1, (float) $f1->net_payable, 18);

        // --- Con saldo: dos compras, una abonada a medias ----------------
        $conSaldo = $this->customer('52984110', 'CAROLINA RAMÍREZ', [
            'credit_limit' => 800000,
            'email' => 'carolina.ramirez@example.co',
        ]);
        $f2 = $this->invoice($conSaldo, 35, [['PERF-003', 1]]);
        $this->pay($f2, (float) $f2->net_payable, 30);
        $f3 = $this->invoice($conSaldo, 12, [['PERF-005', 1], ['PERF-006', 1]]);
        $this->pay($f3, round((float) $f3->net_payable * 0.4, 2), 8);

        // --- Migró del sistema anterior debiendo -------------------------
        $conApertura = $this->customer('79554321', 'JORGE ANDRÉS SOTO', [
            'credit_limit' => 1000000,
            'opening_balance' => 340000,
            'opening_balance_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'email' => 'jorge.soto@example.co',
        ]);
        $f4 = $this->invoice($conApertura, 15, [['PERF-001', 1]]);
        $this->pay($f4, 100000, 10);

        // --- Saldo a favor: abonó más de lo que debía --------------------
        $aFavor = $this->customer('1032889014', 'DIANA PATIÑO', [
            'credit_limit' => 400000,
            'email' => 'diana.patino@example.co',
        ]);
        $f5 = $this->invoice($aFavor, 25, [['PERF-004', 1]]);
        $this->pay($f5, (float) $f5->net_payable, 22);
        $this->advance($aFavor, 150000, 5, 'Consignación Bancolombia 88213');

        // --- Cupo copado: no puede comprar más a crédito -----------------
        $copado = $this->customer('43118902', 'MARTHA LUCÍA GÓMEZ', [
            'credit_limit' => 250000,
            'email' => 'martha.gomez@example.co',
        ]);
        $this->invoice($copado, 18, [['PERF-003', 1]]);

        // --- El caso Broadway --------------------------------------------
        // En el sistema anterior este cliente quedaba con $20.000 a favor Y
        // $20.000 en deuda a la vez. Aquí el anticipo se aplica solo a la
        // factura pendiente y el descuadre no llega a formarse.
        $broadway = $this->customer('1015998877', 'WILSON VILLEGAS', [
            'credit_limit' => 600000,
            'email' => 'wilson.villegas@example.co',
        ]);
        $f6 = $this->invoice($broadway, 9, [['PERF-001', 1], ['PERF-006', 1]]);
        $this->advance($broadway, (float) $f6->net_payable + 20000, 7, 'Efectivo en caja');

        $this->command->line('   · Clientes con cartera: 6');
    }

    /** @param  array<string, mixed>  $extra */
    protected function customer(string $documento, string $nombre, array $extra = []): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'document_type' => 'cc', 'document_number' => $documento],
            array_merge([
                'person_type' => 'natural',
                'name' => $nombre,
                'is_customer' => true,
                'is_supplier' => false,
                'active' => true,
                'city' => 'Bogotá',
                'phone' => '30'.random_int(10000000, 99999999),
            ], $extra),
        );

        // Si ya existía de una corrida anterior, se refrescan los datos de
        // cartera: son justo los que el demo quiere mostrar.
        $cliente->update(array_intersect_key($extra, array_flip([
            'credit_limit', 'opening_balance', 'opening_balance_date', 'email',
        ])));

        return $cliente->fresh();
    }

    /**
     * Factura a crédito ya contabilizada.
     *
     * @param  list<array{0:string, 1:int}>  $items  [código, cantidad]
     */
    protected function invoice(ThirdParty $cliente, int $diasAtras, array $items): SaleInvoice
    {
        $fecha = now()->subDays($diasAtras);

        $existente = SaleInvoice::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('third_party_id', $cliente->id)
            ->whereDate('date', $fecha->toDateString())
            ->first();

        if ($existente) {
            return $existente;
        }

        $invoice = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'third_party_id' => $cliente->id,
            'prefix' => 'DEMO',
            'number' => $this->nextNumber(),
            'invoice_kind' => 'pos',
            'date' => $fecha->toDateString(),
            'due_date' => $fecha->copy()->addDays(30)->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'created_by_user_id' => $this->admin->id,
            'notes' => 'Venta a crédito — demo',
        ]);

        $linea = 1;
        foreach ($items as [$codigo, $cantidad]) {
            $producto = $this->products[$codigo];
            $precio = (float) $producto->default_sale_price;

            SaleInvoiceLine::withoutGlobalScopes()->create([
                'sale_invoice_id' => $invoice->id,
                'line_number' => $linea++,
                'product_id' => $producto->id,
                'description' => $producto->name,
                'quantity' => $cantidad,
                'unit_price' => $precio,
                'subtotal' => round($precio * $cantidad, 2),
                'total' => round($precio * $cantidad, 2),
                'tax_id' => $this->iva19?->id,
            ]);
        }

        return app(SaleInvoiceEngine::class)->post($invoice->fresh(), allowNegativeStock: true);
    }

    protected function pay(SaleInvoice $invoice, float $monto, int $diasAtras): void
    {
        if ($monto <= 0 || (float) $invoice->fresh()->balance <= 0.01) {
            return;
        }

        // Cada factura del demo recibe un solo abono. Sin esta comprobacion,
        // volver a correr el seeder le abona otra vez y el saldo que se queria
        // mostrar se va bajando en cada corrida.
        $yaAbonada = Payment::withoutGlobalScopes()
            ->where('paymentable_type', SaleInvoice::class)
            ->where('paymentable_id', $invoice->id)
            ->exists();

        if ($yaAbonada) {
            return;
        }

        app(SaleInvoiceEngine::class)->addPayment($invoice->fresh(), [
            'date' => now()->subDays($diasAtras)->toDateString(),
            'amount' => min($monto, (float) $invoice->fresh()->balance),
            'payment_method' => 'cash',
            'account_id' => $this->cashAccountId(),
            'reference' => 'Recibo demo',
        ]);
    }

    protected function advance(ThirdParty $cliente, float $monto, int $diasAtras, string $referencia): void
    {
        // Idempotencia: si ya se sembró en una corrida previa, no se repite.
        $yaExiste = CustomerAdvance::withoutGlobalScopes()
            ->where('third_party_id', $cliente->id)
            ->where('reference', $referencia)
            ->exists();

        if ($yaExiste) {
            return;
        }

        app(CustomerAdvanceService::class)->register($cliente, [
            'date' => now()->subDays($diasAtras)->toDateString(),
            'amount' => $monto,
            'payment_method' => 'cash',
            'account_id' => $this->cashAccountId(),
            'reference' => $referencia,
            'notes' => 'Anticipo de demostración',
        ]);
    }

    protected function nextNumber(): int
    {
        return (int) SaleInvoice::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('prefix', 'DEMO')
            ->max('number') + 1;
    }

    protected function cashAccountId(): int
    {
        return (int) Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('code', ['110505', '1105'])
            ->orderByRaw('length(code) desc')
            ->value('id');
    }
}
