<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PaymentMethodOptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una empresa puede cobrar por Nequi.
 *
 * `payments.payment_method` tenía un CHECK con la lista de fábrica, de cuando
 * esa era la única lista que existía. Pero hay una pantalla —Configuración →
 * Métodos de pago— donde cada empresa crea los suyos, con el código libre, y el
 * formulario hasta sugiere `my_voucher` como ejemplo.
 *
 * El método se creaba, salía en los desplegables, el cajero lo elegía... y el
 * pago reventaba con un error 500 al guardarse. El sistema invitaba a hacer
 * algo que la base rechazaba.
 *
 * Es la misma clase de problema que tuvo `journal_entries.type`, pero aquí no
 * se arregla ampliando la lista: los valores válidos son datos de cada empresa.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CustomPaymentMethodTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        PaymentMethodOptions::olvidarCache();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        PaymentMethodOptions::olvidarCache();

        parent::tearDown();
    }

    /**
     * La base ya no impone la lista de fábrica.
     *
     * Mientras exista el CHECK, ninguna empresa puede cobrar por un medio que
     * no estuviera previsto el día que se escribió la migración.
     */
    public function test_la_base_no_impone_la_lista_de_fabrica(): void
    {
        $restricciones = DB::select(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE contype = 'c'
              AND conrelid = 'payments'::regclass
              AND pg_get_constraintdef(oid) ILIKE '%payment_method%'
        SQL);

        $this->assertSame([], $restricciones,
            'Los métodos de pago son datos de cada empresa: no se pueden enumerar en un CHECK.');
    }

    /** Y un método propio se puede cobrar de verdad. */
    public function test_se_puede_cobrar_con_un_metodo_propio(): void
    {
        $metodo = PaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'zz_nequi',
            'name' => 'Nequi ZZ',
            'type' => array_key_first(PaymentMethod::TYPES),
            'active' => true,
            'sort_order' => 99,
        ]);
        $this->limpiar[] = fn () => DB::table('payment_methods')->where('id', $metodo->id)->delete();
        PaymentMethodOptions::olvidarCache();

        $factura = $this->facturaContabilizada();

        $pago = Payment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'paymentable_type' => SaleInvoice::class,
            'paymentable_id' => $factura->id,
            'third_party_id' => $factura->third_party_id,
            'date' => now()->toDateString(),
            'amount' => 15000,
            'payment_method' => 'zz_nequi',
            'created_by_user_id' => $this->user->id,
        ]);
        $this->limpiar[] = fn () => DB::table('payments')->where('id', $pago->id)->delete();

        $this->assertDatabaseHas('payments', [
            'id' => $pago->id,
            'payment_method' => 'zz_nequi',
        ]);
    }

    /** Y se lee con el nombre que le puso la empresa, no con el código. */
    public function test_se_lee_con_el_nombre_que_le_puso_la_empresa(): void
    {
        $metodo = PaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'zz_daviplata',
            'name' => 'Daviplata ZZ',
            'type' => array_key_first(PaymentMethod::TYPES),
            'active' => true,
            'sort_order' => 99,
        ]);
        $this->limpiar[] = fn () => DB::table('payment_methods')->where('id', $metodo->id)->delete();
        PaymentMethodOptions::olvidarCache();

        $this->assertSame('Daviplata ZZ', PaymentMethodOptions::nombre('zz_daviplata'),
            'Ver «zz_daviplata» en un recibo es ver el nombre de una columna.');
    }

    // --------------------------------------------------------- auxiliares

    private function facturaContabilizada(): SaleInvoice
    {
        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $sede->id,
            'third_party_id' => $tercero->id,
            'prefix' => 'ZZMP',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_POSTED,
            'subtotal' => 100000,
            'tax_total' => 0,
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }
}
