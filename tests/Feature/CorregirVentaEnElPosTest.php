<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\EditSaleInvoice;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\ViewSaleInvoice;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Una venta hecha en el POS se corrige en el POS.
 *
 * Antes, corregir una venta del mostrador abria el formulario completo de
 * facturacion —cliente, impuestos, retenciones, resolucion DIAN—, que es
 * exactamente la pantalla de la que el POS existe para librar al cajero. La
 * correccion tiene que ocurrir donde ocurrio la venta.
 *
 * Lo que decide a donde ir es `origin`, no `invoice_kind`: esa columna dice
 * que CLASE de documento es (tiquete POS o factura electronica), no de donde
 * salio. El POS se puede configurar para emitir electronicas y desde el
 * formulario se pueden emitir documentos tipo POS, asi que usar una por la
 * otra manda al usuario a la pantalla equivocada justo en los casos raros.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CorregirVentaEnElPosTest extends TestCase
{
    use FuerzaPosDeRetail;

    private Company $empresa;

    private User $usuario;

    private Location $sede;

    private Product $producto;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);

        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->fuerzaPosDeRetail($this->empresa, $this->limpiar);
        $this->producto = $this->productoDePrueba();
        $this->resolucionPos();
        $this->turnoAbierto();
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    // ------------------------------------------------------------- origen

    /** Una venta del POS queda marcada como tal. */
    public function test_la_venta_del_pos_nace_marcada(): void
    {
        $factura = $this->ventaEnElPos(2);

        $this->assertSame(SaleInvoice::ORIGIN_POS, $factura->origin);
        $this->assertTrue($factura->naceEnElPos());
    }

    /**
     * El tipo de documento no decide el origen.
     *
     * Es el error que esto viene a evitar: una venta del POS emitida como
     * factura electronica sigue siendo del POS, y una del formulario emitida
     * como tiquete POS sigue siendo del formulario.
     */
    public function test_el_tipo_de_documento_no_decide_el_origen(): void
    {
        $delFormulario = $this->ventaDelFormulario();
        $delFormulario->update(['invoice_kind' => 'pos']);

        $this->assertTrue($delFormulario->fresh()->isPosInvoice(),
            'El documento es un tiquete POS…');
        $this->assertFalse($delFormulario->fresh()->naceEnElPos(),
            '…pero no nació en el POS, así que no se corrige ahí.');
    }

    // ---------------------------------------------------------- cargar

    /** Corregir carga la venta en el carrito, con sus líneas. */
    public function test_corregir_carga_la_venta_en_el_carrito(): void
    {
        $factura = $this->ventaEnElPos(3);

        $pos = Livewire::test(PosTerminal::class)->call('corregirVenta', $factura->id);

        $carrito = $pos->instance()->cart;

        $this->assertCount(1, $carrito);
        $this->assertSame($this->producto->id, $carrito[0]['product_id']);
        $this->assertEqualsWithDelta(3, (float) $carrito[0]['quantity'], 0.01);
        $this->assertSame($factura->id, $pos->instance()->corrigiendo_factura_id);
    }

    /**
     * Cargarla NO la desarma.
     *
     * Es lo que la hace segura: el cajero abre una corrección, entra el
     * siguiente cliente y la deja a medias. Si al abrirla se devolviera a
     * borrador —como hace el formulario— esa venta quedaría sin contabilizar
     * y sin pagos, desaparecida del arqueo, sin que nadie se entere hasta
     * cuadrar la caja.
     */
    public function test_cargarla_no_toca_la_venta(): void
    {
        $factura = $this->ventaEnElPos(2);

        Livewire::test(PosTerminal::class)->call('corregirVenta', $factura->id);

        $fresca = $factura->fresh();

        $this->assertTrue($fresca->isPosted(), 'La venta sigue contabilizada.');
        $this->assertGreaterThan(0, (float) $fresca->paid_amount, 'Y sigue pagada.');
    }

    /** Cancelar la deja como estaba y vacía el carrito. */
    public function test_cancelar_la_correccion_no_deja_rastro(): void
    {
        $factura = $this->ventaEnElPos(2);

        $pos = Livewire::test(PosTerminal::class)
            ->call('corregirVenta', $factura->id)
            ->call('cancelarCorreccion');

        $this->assertNull($pos->instance()->corrigiendo_factura_id);
        $this->assertSame([], $pos->instance()->cart);
        $this->assertTrue($factura->fresh()->isPosted());
    }

    // ------------------------------------------------------ el viaje completo

    /**
     * Guardar la corrección reescribe la MISMA venta.
     *
     * Es lo que separa esto de «vender otra vez»: el documento conserva su
     * número, sus líneas se reemplazan por las del carrito y el cobro se
     * vuelve a registrar. Si naciera una factura nueva, la vieja quedaría
     * viva en la cartera y el cliente tendría dos por una compra.
     */
    public function test_guardar_la_correccion_reescribe_la_misma_venta(): void
    {
        $factura = $this->ventaEnElPos(3);
        $numeroOriginal = $factura->number;
        $cuantasHabia = $this->cuantasFacturas();

        $pos = Livewire::test(PosTerminal::class)->call('corregirVenta', $factura->id);

        // El cliente se arrepintió de dos de las tres.
        $carrito = $pos->instance()->cart;
        $carrito[0]['quantity'] = 1.0;
        $pos->set('cart', $carrito);

        $nuevoTotal = $pos->instance()->totals()['net_payable'];

        $pos->set('payments', [[
            'payment_method' => 'cash',
            'amount' => $nuevoTotal,
            'account_id' => $this->cuentaDeCaja(),
            'cash_received' => $nuevoTotal,
        ]])->call('processSale');

        $this->assertNull($pos->instance()->paymentError,
            'La corrección no se pudo guardar: '.($pos->instance()->paymentError ?? ''));

        $fresca = $factura->fresh(['lines']);

        $this->assertSame($numeroOriginal, $fresca->number, 'Conserva su consecutivo.');
        $this->assertSame($cuantasHabia, $this->cuantasFacturas(),
            'No puede nacer una factura nueva: la vieja quedaría viva en la cartera.');
        $this->assertCount(1, $fresca->lines);
        $this->assertEqualsWithDelta(1, (float) $fresca->lines->first()->quantity, 0.01);
        $this->assertTrue($fresca->isPosted(), 'Queda contabilizada otra vez, no en borrador.');
        $this->assertEqualsWithDelta($nuevoTotal, (float) $fresca->paid_amount, 0.01,
            'Y cobrada por el valor corregido.');
    }

    /**
     * Después de guardar, el POS queda libre.
     *
     * Si el carrito se quedara pegado a la venta corregida, la siguiente
     * venta del día se cobraría encima de una factura vieja.
     */
    public function test_despues_de_guardar_el_pos_queda_libre(): void
    {
        $factura = $this->ventaEnElPos(2);

        $pos = Livewire::test(PosTerminal::class)->call('corregirVenta', $factura->id);

        $total = $pos->instance()->totals()['net_payable'];

        $pos->set('payments', [[
            'payment_method' => 'cash',
            'amount' => $total,
            'account_id' => $this->cuentaDeCaja(),
            'cash_received' => $total,
        ]])->call('processSale');

        $this->assertNull($pos->instance()->corrigiendo_factura_id);
        $this->assertSame([], $pos->instance()->cart);
    }

    // --------------------------------------------------------- enrutado

    /**
     * El enlace directo al formulario devuelve al POS.
     *
     * El botón ya manda al sitio correcto, pero el enlace a la pantalla de
     * edición sigue existiendo —un marcador, el historial, un enlace pegado
     * en un chat— y por ahí el cajero terminaba igual en el formulario.
     */
    public function test_el_formulario_de_edicion_devuelve_al_pos(): void
    {
        $factura = $this->ventaEnElPos(1);
        $factura->update(['status' => SaleInvoice::STATUS_DRAFT]);

        Livewire::test(EditSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->assertRedirect(PosTerminal::getUrl(['corregir' => $factura->id]));
    }

    /** Y una del formulario se queda en el formulario. */
    public function test_una_venta_del_formulario_no_se_va_al_pos(): void
    {
        $factura = $this->ventaDelFormulario();

        Livewire::test(EditSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->assertOk()
            ->assertNoRedirect();
    }

    /** Desde la ficha de la factura, «Corregir» lleva al POS. */
    public function test_corregir_desde_la_ficha_lleva_al_pos(): void
    {
        $factura = $this->ventaEnElPos(2);

        Livewire::test(ViewSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('editarFactura')
            ->assertRedirect(PosTerminal::getUrl(['corregir' => $factura->id]));
    }

    // -------------------------------------------------------- la guarda

    /** Una venta del turno abierto de otro cajero no se toca. */
    public function test_no_se_corrige_la_venta_de_otro_turno_abierto(): void
    {
        $factura = $this->ventaEnElPos(1);

        // De OTRO cajero: si fuera del mismo, el POS lo tomaría como su
        // propio turno al montar y no habría nada que impedir.
        $otro = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->otroCajero()->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);
        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $otro->id)->delete();

        $factura->update(['cash_register_session_id' => $otro->id]);

        $pos = Livewire::test(PosTerminal::class);
        $motivo = $pos->instance()->porQueNoSePuedeCorregirAqui($factura->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('otro cajero', $motivo);
    }

    // --------------------------------------------------------- auxiliares

    /** Una venta de verdad, cobrada por el POS de punta a punta. */
    private function ventaEnElPos(float $cantidad): SaleInvoice
    {
        $pos = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $this->producto->id);

        $instancia = $pos->instance();
        $carrito = $instancia->cart;
        $carrito[0]['quantity'] = $cantidad;

        // set() dispara updatedCart(), que es quien recalcula.
        $pos->set('cart', $carrito);

        $total = $pos->instance()->totals()['net_payable'];

        $pos->set('payments', [[
            'payment_method' => 'cash',
            'amount' => $total,
            'account_id' => $this->cuentaDeCaja(),
            'cash_received' => $total,
        ]])->call('processSale');

        $this->assertNull($pos->instance()->paymentError,
            'La venta de la prueba no se pudo cobrar: '.($pos->instance()->paymentError ?? ''));

        $factura = SaleInvoice::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->empresa->id)
            ->latest('id')->firstOrFail();

        $this->olvidarFactura($factura);

        return $factura;
    }

    private function ventaDelFormulario(): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => DB::table('third_parties')
                ->where('company_id', $this->empresa->id)->orderBy('id')->value('id'),
            'prefix' => 'ZZOR',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'origin' => SaleInvoice::ORIGIN_FORM,
            'subtotal' => 10000,
            'total' => 10000,
            'net_payable' => 10000,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }

    private function olvidarFactura(SaleInvoice $factura): void
    {
        $this->limpiar[] = function () use ($factura) {
            $asientos = DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)
                ->where('reference', 'like', '%'.$factura->number.'%')
                ->pluck('id');

            DB::table('payments')->where('paymentable_id', $factura->id)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)
                ->where('reference_type', SaleInvoice::class)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoice_retentions')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };
    }

    private function productoDePrueba(): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZPOS'.random_int(10000, 99999),
            'name' => 'ZZPOS Producto corrección',
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 15000,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()
            ->whereKey($producto->id)->forceDelete();

        return $producto;
    }

    /** Sin resolución POS la sede no puede emitir, y la venta ni nace. */
    private function resolucionPos(): void
    {
        $yaTiene = DB::table('dian_location_resolutions as lr')
            ->join('dian_resolutions as r', 'r.id', '=', 'lr.dian_resolution_id')
            ->where('lr.location_id', $this->sede->id)
            ->where('lr.active', true)
            ->where('r.kind', 'pos')
            ->where('r.active', true)
            ->exists();

        if ($yaTiene) {
            return;
        }

        $resolucionId = DB::table('dian_resolutions')->insertGetId([
            'company_id' => $this->empresa->id,
            'document_type_id' => 1,
            'document_type_name' => 'Factura de venta',
            'kind' => 'pos',
            'prefix' => 'ZZP',
            'resolution_number' => 'ZZ-PRUEBA',
            'range_from' => 1,
            'range_to' => 999999,
            'date_from' => now()->subYear()->toDateString(),
            'date_to' => now()->addYear()->toDateString(),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enlaceId = DB::table('dian_location_resolutions')->insertGetId([
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $resolucionId,
            'current_consecutive' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->limpiar[] = function () use ($enlaceId, $resolucionId) {
            DB::table('dian_location_resolutions')->where('id', $enlaceId)->delete();
            DB::table('dian_resolutions')->where('id', $resolucionId)->delete();
        };
    }

    private function turnoAbierto(): void
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->usuario->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }

    /** Otro usuario de la empresa, para el turno que no es el del cajero. */
    private function otroCajero(): User
    {
        $existente = User::query()
            ->where('company_id', $this->empresa->id)
            ->where('id', '!=', $this->usuario->id)
            ->orderBy('id')
            ->first();

        if ($existente) {
            return $existente;
        }

        $nuevo = User::create([
            'company_id' => $this->empresa->id,
            'name' => 'ZZ Cajero',
            'email' => 'zz-cajero-'.random_int(10000, 99999).'@prueba.local',
            'password' => bcrypt(Str::random(32)),
        ]);

        $this->limpiar[] = fn () => DB::table('users')->where('id', $nuevo->id)->delete();

        return $nuevo;
    }

    private function cuantasFacturas(): int
    {
        return SaleInvoice::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->empresa->id)
            ->count();
    }

    private function cuentaDeCaja(): ?int
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('accepts_movements', true)
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->value('id');
    }
}
