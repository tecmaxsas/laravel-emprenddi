<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\CustomerAdvanceService;
use App\Services\Sales\CustomerCreditGuard;
use App\Services\Sales\CustomerStatement;
use App\Services\ThirdParties\ThirdPartyImportTemplateGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Cartera del cliente: anticipos y hoja de cuenta.
 *
 * El caso que hay que impedir es el de Perfumería Broadway: un cliente con
 * $20.000 a favor y $20.000 en deuda al mismo tiempo, sin que nadie sepa si
 * debe o le deben. Sale de dejar un anticipo suelto mientras hay facturas
 * pendientes, así que eso es lo que más se prueba aquí.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CustomerAccountTest extends TestCase
{
    private Company $company;

    private int $companyId;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->firstOrFail();
        $this->companyId = (int) $user->company_id;
        $this->company = Company::findOrFail($this->companyId);
        $this->actingAs($user);

        $this->cliente = $this->crearCliente();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    public function test_un_anticipo_sin_facturas_queda_como_saldo_a_favor(): void
    {
        $this->servicio()->register($this->cliente, ['amount' => 50000]);

        $this->assertSame(50000.0, $this->servicio()->availableBalance($this->cliente));

        $hoja = app(CustomerStatement::class)->build($this->cliente);

        $this->assertSame(-50000.0, $hoja['due'], 'Un saldo a favor es un saldo negativo, no una deuda.');
        $this->assertSame(50000.0, $hoja['advance_balance']);
    }

    /**
     * El caso Broadway. Con una factura pendiente, el anticipo tiene que irse
     * contra ella de inmediato: si queda suelto, el cliente aparece debiendo y
     * teniendo a favor al mismo tiempo.
     */
    public function test_el_anticipo_se_aplica_solo_a_la_factura_pendiente(): void
    {
        $factura = $this->facturaContabilizada(80000);

        $this->servicio()->register($this->cliente, ['amount' => 80000]);

        $this->assertSame(0.0, $this->servicio()->availableBalance($this->cliente),
            'El anticipo quedó suelto: eso produce el saldo a favor y la deuda a la vez.');
        $this->assertEqualsWithDelta(0, (float) $factura->fresh()->balance, 0.01);

        $hoja = app(CustomerStatement::class)->build($this->cliente);
        $this->assertEqualsWithDelta(0, $hoja['due'], 0.01);
    }

    /** Si el anticipo es mayor que la deuda, el sobrante sí queda a favor. */
    public function test_el_sobrante_del_anticipo_queda_a_favor(): void
    {
        $factura = $this->facturaContabilizada(50000);

        $this->servicio()->register($this->cliente, ['amount' => 80000]);

        $this->assertEqualsWithDelta(0, (float) $factura->fresh()->balance, 0.01);
        $this->assertSame(30000.0, $this->servicio()->availableBalance($this->cliente));
    }

    /** Y si es menor, abona lo que alcance y la factura queda parcial. */
    public function test_un_anticipo_menor_deja_la_factura_parcial(): void
    {
        $factura = $this->facturaContabilizada(100000);

        $this->servicio()->register($this->cliente, ['amount' => 40000]);

        $this->assertEqualsWithDelta(60000, (float) $factura->fresh()->balance, 0.01);
        $this->assertSame(0.0, $this->servicio()->availableBalance($this->cliente));
        $this->assertSame(SaleInvoice::PAYMENT_PARCIAL, $factura->fresh()->payment_status);
    }

    /** Las facturas se cubren de la más antigua a la más reciente. */
    public function test_el_anticipo_va_primero_contra_la_factura_mas_vieja(): void
    {
        $vieja = $this->facturaContabilizada(30000, '2026-01-10');
        $nueva = $this->facturaContabilizada(30000, '2026-02-10');

        $this->servicio()->register($this->cliente, ['amount' => 30000]);

        $this->assertEqualsWithDelta(0, (float) $vieja->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(30000, (float) $nueva->fresh()->balance, 0.01);
    }

    /** Un anticipo aplicado del todo no puede volver a usarse. */
    public function test_no_se_aplica_mas_de_lo_que_tiene_el_anticipo(): void
    {
        $factura = $this->facturaContabilizada(20000);
        $this->servicio()->register($this->cliente, ['amount' => 20000]);

        $anticipo = CustomerAdvance::query()->where('third_party_id', $this->cliente->id)->firstOrFail();
        $otra = $this->facturaContabilizada(50000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/saldo suficiente/');

        $this->servicio()->applyAdvanceToInvoice($anticipo->fresh(), $otra, 10000);
    }

    public function test_un_anticipo_de_otro_cliente_no_se_aplica(): void
    {
        $otroCliente = $this->crearCliente('ZZOTRO');
        $this->servicio()->register($otroCliente, ['amount' => 50000]);
        $anticipo = CustomerAdvance::query()->where('third_party_id', $otroCliente->id)->firstOrFail();

        $factura = $this->facturaContabilizada(50000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/otro cliente/');

        $this->servicio()->applyAdvanceToInvoice($anticipo, $factura, 10000);
    }

    /** El saldo que traía del sistema anterior abre la hoja. */
    public function test_el_saldo_de_apertura_abre_la_hoja_y_cuenta_como_deuda(): void
    {
        $this->cliente->update([
            'opening_balance' => 120000,
            'opening_balance_date' => '2026-01-01',
        ]);

        $hoja = app(CustomerStatement::class)->build($this->cliente->fresh());

        $primero = $hoja['movements']->first();

        $this->assertSame(CustomerStatement::OPENING, $primero['type']);
        $this->assertSame(120000.0, $primero['debit']);
        $this->assertSame(120000.0, $hoja['due']);
    }

    /** El saldo de cada línea es el acumulado hasta ese movimiento. */
    public function test_el_saldo_corrido_acumula_movimiento_a_movimiento(): void
    {
        $this->cliente->update(['opening_balance' => 100000, 'opening_balance_date' => '2026-01-01']);
        $this->facturaContabilizada(50000, '2026-02-01');

        $hoja = app(CustomerStatement::class)->build($this->cliente->fresh());
        $saldos = $hoja['movements']->pluck('balance')->all();

        $this->assertSame([100000.0, 150000.0], $saldos);
        $this->assertSame(150000.0, $hoja['due']);
    }

    /**
     * Un anticipo parcialmente aplicado aparece dos veces —como anticipo y
     * como el pago que generó—. Si se contara completo en los dos sitios, el
     * saldo restaría el doble.
     */
    public function test_un_anticipo_aplicado_no_se_cuenta_dos_veces(): void
    {
        $this->facturaContabilizada(30000, '2026-02-01');
        $this->servicio()->register($this->cliente, ['amount' => 50000, 'date' => '2026-02-02']);

        $hoja = app(CustomerStatement::class)->build($this->cliente->fresh());

        // 30.000 facturados - 30.000 aplicados - 20.000 de sobrante = -20.000
        $this->assertSame(-20000.0, $hoja['due']);
        $this->assertSame(20000.0, $hoja['advance_balance']);
    }

    /**
     * La migración desde el otro sistema entra por el importador de terceros,
     * así que el saldo tiene que traer fecha: sin ella la hoja abre con una
     * línea que no se puede ordenar ni explicar.
     */
    public function test_el_importador_exige_fecha_para_el_saldo_de_apertura(): void
    {
        $columnas = ThirdPartyImportTemplateGenerator::COLUMNS;

        $this->assertContains('opening_balance', $columnas);
        $this->assertContains('opening_balance_date', $columnas);
    }

    /**
     * Un cupo en 0 significa SIN LÍMITE. Es como viene la mayoría de terceros,
     * así que si bloqueara, activar la validación dejaría a toda la base sin
     * poder vender a crédito.
     */
    public function test_un_cupo_en_cero_no_bloquea_nada(): void
    {
        $this->cliente->update(['credit_limit' => 0]);

        $factura = $this->facturaBorrador(5000000);

        app(CustomerCreditGuard::class)->assertWithinLimit($factura);

        $this->assertNull(app(CustomerCreditGuard::class)->availableCredit($this->cliente->fresh()));
    }

    public function test_la_venta_que_pasa_el_cupo_no_se_contabiliza(): void
    {
        $this->cliente->update(['credit_limit' => 100000]);
        $this->facturaContabilizada(80000);

        $nueva = $this->facturaBorrador(50000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cupo/');

        app(CustomerCreditGuard::class)->assertWithinLimit($nueva);
    }

    public function test_la_venta_que_cabe_en_el_cupo_pasa(): void
    {
        $this->cliente->update(['credit_limit' => 100000]);
        $this->facturaContabilizada(80000);

        $nueva = $this->facturaBorrador(20000);

        app(CustomerCreditGuard::class)->assertWithinLimit($nueva);

        $this->assertSame(20000.0, app(CustomerCreditGuard::class)->availableCredit($this->cliente->fresh()));
    }

    /** El saldo que venía del otro sistema también consume cupo. */
    public function test_el_saldo_de_apertura_consume_cupo(): void
    {
        $this->cliente->update(['credit_limit' => 100000, 'opening_balance' => 90000,
            'opening_balance_date' => '2026-01-01']);

        $nueva = $this->facturaBorrador(20000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cupo/');

        app(CustomerCreditGuard::class)->assertWithinLimit($nueva->fresh());
    }

    /** Una factura ya pagada no consume cupo por mucho que valga. */
    public function test_lo_pagado_no_consume_cupo(): void
    {
        $this->cliente->update(['credit_limit' => 50000]);

        $factura = $this->facturaBorrador(500000);
        $factura->update(['paid_amount' => 500000]);

        app(CustomerCreditGuard::class)->assertWithinLimit($factura->fresh());

        $this->assertTrue(true, 'No debía lanzar: no queda nada por cobrar.');
    }

    /** El saldo a favor libera cupo: es plata que el cliente ya puso. */
    public function test_el_saldo_a_favor_libera_cupo(): void
    {
        $this->cliente->update(['credit_limit' => 100000]);
        $this->facturaContabilizada(80000);
        $this->servicio()->register($this->cliente, ['amount' => 80000]);

        $this->assertSame(100000.0, app(CustomerCreditGuard::class)->availableCredit($this->cliente->fresh()),
            'El anticipo cubrió la factura, así que el cupo vuelve a estar completo.');
    }

    private function servicio(): CustomerAdvanceService
    {
        return app(CustomerAdvanceService::class);
    }

    private function crearCliente(string $sufijo = 'ZZCARTERA'): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $sufijo.random_int(100000, 999999),
            'name' => 'CLIENTE '.$sufijo,
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($cliente) {
            $facturas = SaleInvoice::withoutGlobalScopes()->where('third_party_id', $cliente->id)->pluck('id');
            Payment::withoutGlobalScopes()->whereIn('paymentable_id', $facturas)->delete();
            CustomerAdvance::withoutGlobalScopes()->where('third_party_id', $cliente->id)->delete();
            DB::table('sale_invoice_lines')->whereIn('sale_invoice_id', $facturas)->delete();
            SaleInvoice::withoutGlobalScopes()->whereIn('id', $facturas)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($cliente->id)->forceDelete();
        };

        return $cliente;
    }

    /**
     * Factura ya contabilizada, que es la única sobre la que se pueden
     * registrar pagos.
     */
    private function facturaContabilizada(float $total, ?string $fecha = null): SaleInvoice
    {
        return $this->factura($total, $fecha, 'posted');
    }

    /** Sin contabilizar: es el estado en el que se valida el cupo. */
    private function facturaBorrador(float $total, ?string $fecha = null): SaleInvoice
    {
        return $this->factura($total, $fecha, 'draft');
    }

    private function factura(float $total, ?string $fecha, string $estado): SaleInvoice
    {
        return SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'location_id' => Location::withoutGlobalScopes()
                ->where('company_id', $this->companyId)->value('id'),
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZ',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => $fecha ?? now()->toDateString(),
            'currency' => 'COP',
            'status' => $estado,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
            'paid_amount' => 0,
        ]);
    }
}
