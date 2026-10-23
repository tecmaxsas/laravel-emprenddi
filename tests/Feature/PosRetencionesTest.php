<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Sobre qué monto retiene el POS.
 *
 * No es el mismo para todas: retefuente y ReteICA van sobre el subtotal antes
 * de IVA; **ReteIVA va sobre el IVA**. Con un IVA del 19%, aplicarle la tarifa
 * al subtotal retiene más de cinco veces lo que corresponde, y nadie lo nota
 * hasta que el cliente reclama que le retuvieron de más.
 *
 * La regla vive en `RetentionBase` desde que se descubrió escrita dos veces.
 * El POS se había quedado con su propia copia: era la tercera, y la que
 * faltaba por corregir.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PosRetencionesTest extends TestCase
{
    use FuerzaPosDeRetail;

    private Company $company;

    private User $user;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->fuerzaPosDeRetail($this->company, $this->limpiar);
        $this->abrirCaja();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /**
     * ReteIVA se calcula sobre el IVA.
     *
     * Es el error que motivó esto: con base de $100.000 e IVA del 19%, una
     * ReteIVA del 15% son $2.850 —el 15% de $19.000—, no $15.000.
     */
    public function test_el_reteiva_va_sobre_el_iva(): void
    {
        $iva = $this->impuesto('ZZIVA19', 'IVA 19 ZZ', 'vat', 19);
        $reteiva = $this->impuesto('ZZRIVA', 'ReteIVA ZZ', 'vat_withholding', 15);

        $producto = $this->producto('ZZRET-01', 100000, $iva->id);

        $pos = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $producto->id)
            ->call('addRetention', $reteiva->id);

        $retenciones = $pos->get('retentions');

        $this->assertCount(1, $retenciones);
        $this->assertEqualsWithDelta(19000, (float) $retenciones[0]['base_amount'], 1,
            'La base de ReteIVA es el IVA del documento, no el subtotal.');
        $this->assertEqualsWithDelta(2850, (float) $retenciones[0]['amount'], 1,
            'El 15% de $19.000. Sobre el subtotal habría dado $15.000.');
    }

    /** Retefuente sigue yendo sobre el subtotal. */
    public function test_la_retefuente_va_sobre_el_subtotal(): void
    {
        $iva = $this->impuesto('ZZIVA19B', 'IVA 19 ZZ B', 'vat', 19);
        $rtf = $this->impuesto('ZZRTF', 'ReteFuente ZZ', 'income_withholding', 2.5);

        $producto = $this->producto('ZZRET-02', 100000, $iva->id);

        $pos = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $producto->id)
            ->call('addRetention', $rtf->id);

        $retenciones = $pos->get('retentions');

        $this->assertEqualsWithDelta(100000, (float) $retenciones[0]['base_amount'], 1);
        $this->assertEqualsWithDelta(2500, (float) $retenciones[0]['amount'], 1);
    }

    /** Y ReteICA también, que es la otra que va sobre el subtotal. */
    public function test_el_reteica_va_sobre_el_subtotal(): void
    {
        $iva = $this->impuesto('ZZIVA19C', 'IVA 19 ZZ C', 'vat', 19);
        $ica = $this->impuesto('ZZICA', 'ReteICA ZZ', 'ica_withholding', 1);

        $producto = $this->producto('ZZRET-03', 100000, $iva->id);

        $pos = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $producto->id)
            ->call('addRetention', $ica->id);

        $this->assertEqualsWithDelta(100000, (float) $pos->get('retentions')[0]['base_amount'], 1);
    }

    /**
     * Agregar un producto después actualiza la base.
     *
     * La base se fijaba al agregar la retención y se quedaba ahí: la factura
     * salía con una cifra calculada sobre un carrito que ya no existía.
     */
    public function test_cambiar_el_carrito_recalcula_la_base(): void
    {
        $iva = $this->impuesto('ZZIVA19D', 'IVA 19 ZZ D', 'vat', 19);
        $reteiva = $this->impuesto('ZZRIVA2', 'ReteIVA ZZ 2', 'vat_withholding', 15);

        $producto = $this->producto('ZZRET-04', 100000, $iva->id);

        $pos = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $producto->id)
            ->call('addRetention', $reteiva->id)
            ->call('addProductToCart', $producto->id);

        $retenciones = $pos->get('retentions');

        // Dos unidades: IVA de $38.000, ReteIVA de $5.700.
        $this->assertEqualsWithDelta(38000, (float) $retenciones[0]['base_amount'], 1,
            'Sin recalcular, la retención queda sobre un carrito viejo.');
        $this->assertEqualsWithDelta(5700, (float) $retenciones[0]['amount'], 1);
    }

    /**
     * Pero la base escrita a mano se respeta.
     *
     * Si el cajero la corrigió fue por algo; pisársela al siguiente cambio del
     * carrito le borra el trabajo sin avisar.
     */
    public function test_la_base_escrita_a_mano_no_se_pisa(): void
    {
        $iva = $this->impuesto('ZZIVA19E', 'IVA 19 ZZ E', 'vat', 19);
        $reteiva = $this->impuesto('ZZRIVA3', 'ReteIVA ZZ 3', 'vat_withholding', 15);

        $producto = $this->producto('ZZRET-05', 100000, $iva->id);

        $pos = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $producto->id)
            ->call('addRetention', $reteiva->id);

        $retenciones = $pos->get('retentions');
        $retenciones[0]['base_amount'] = 10000;

        $pos->set('retentions', $retenciones)
            ->call('addProductToCart', $producto->id);

        $this->assertEqualsWithDelta(10000, (float) $pos->get('retentions')[0]['base_amount'], 1,
            'El cajero la corrigió: no se le pisa.');
    }

    /**
     * El POS usa la misma regla que las otras pantallas.
     *
     * Era la tercera copia de una regla fiscal. Dos copias ya se habían
     * separado una vez, y la que se quedó atrás daba cifras que parecían
     * correctas.
     */
    public function test_el_pos_no_tiene_su_propia_copia_de_la_regla(): void
    {
        $fuente = file_get_contents(app_path('Filament/App/Pages/PosTerminal.php'));

        $this->assertStringContainsString('RetentionBase::para', $fuente);
        $this->assertStringNotContainsString(
            "\$base = max(0, \$totals['subtotal'] - \$totals['discount']);",
            $fuente,
            'Esa es la base de retefuente aplicada a todas: infla el ReteIVA.',
        );
    }

    // --------------------------------------------------------- auxiliares

    private function impuesto(string $codigo, string $nombre, string $tipo, float $tarifa): Tax
    {
        $impuesto = Tax::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => $nombre,
            'type' => $tipo,
            'rate' => $tarifa,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('taxes')->where('id', $impuesto->id)->delete();

        return $impuesto;
    }

    private function producto(string $codigo, float $precio, int $taxId): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ '.$codigo,
            'type' => 'good',
            'active' => true,
            'is_sellable' => true,
            'track_inventory' => false,
            'default_sale_price' => $precio,
            'sale_price_includes_tax' => false,
            'default_sale_tax_id' => $taxId,
        ]);

        $this->limpiar[] = fn () => DB::table('products')->where('id', $producto->id)->delete();

        return $producto;
    }

    private function abrirCaja(): void
    {
        $abierto = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('cashier_user_id', $this->user->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->exists();

        if ($abierto) {
            return;
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }
}
