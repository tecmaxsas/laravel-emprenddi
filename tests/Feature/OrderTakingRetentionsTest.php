<?php

namespace Tests\Feature;

use App\Filament\App\Pages\OrderTaking\NewOrder;
use App\Models\Company;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\OrderTaking\OrderEngine;
use App\Support\CurrentCompany;
use App\Support\RetentionBase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Retenciones en toma de pedidos.
 *
 * Tres cosas estaban mal y solo una se veía.
 *
 * **La base no dependía del tipo.** Retefuente y ReteICA van sobre el subtotal
 * antes de IVA; ReteIVA va sobre el IVA. Aquí se le aplicaba la misma base a
 * todas, así que una ReteIVA del 15% sobre un subtotal de 70.588 retenía 10.588
 * en lugar de los 2.012 que corresponden al IVA. Eso no se nota al guardar el
 * pedido: se nota cuando el cliente reclama que le retuvieron de más.
 *
 * **No se podían agregar retenciones.** Solo quitarlas. Y el botón de
 * restaurarlas aparecía únicamente cuando la lista quedaba vacía, así que quitar
 * una de dos era irreversible sin borrar la otra.
 *
 * **La base editada a mano se leía con `(float)`.** En un equipo en español el
 * campo entrega «70588,24» y PHP lo corta en 70588 —o convierte «1.500.000» en
 * 1.5—. Una base mal leída no falla: retiene de menos, en silencio.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class OrderTakingRetentionsTest extends TestCase
{
    private Company $company;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->activarTomaDePedidos();

        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZRET CLIENTE',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('third_party_retentions')->where('third_party_id', $this->cliente->id)->delete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // --------------------------------------------- la base segun el tipo

    /**
     * El fallo principal: ReteIVA iba sobre el subtotal.
     *
     * Con subtotal 70.588 e IVA 13.412, una ReteIVA del 15% debe retener 2.012 —
     * el 15% del IVA—, no 10.588, que es el 15% del subtotal. Cinco veces más.
     */
    public function test_reteiva_va_sobre_el_iva_y_no_sobre_el_subtotal(): void
    {
        $reteIva = $this->impuesto('ZZRET-IVA', 'vat_withholding', 15);
        $this->asignarAlCliente($reteIva);

        $filas = app(OrderEngine::class)->suggestRetentionsFor($this->cliente, 70588.24, 13411.76);

        $this->assertCount(1, $filas);
        $this->assertEqualsWithDelta(13411.76, $filas[0]['base_amount'], 0.01,
            'La base de una ReteIVA es el IVA del documento.');
        $this->assertEqualsWithDelta(2011.76, $filas[0]['amount'], 0.01,
            'Sobre el subtotal habría retenido 10.588: cinco veces de más.');
    }

    /** Retefuente y ReteICA sí van sobre el subtotal. */
    public function test_retefuente_y_reteica_van_sobre_el_subtotal(): void
    {
        $retefuente = $this->impuesto('ZZRET-FTE', 'income_withholding', 2.5);
        $reteIca = $this->impuesto('ZZRET-ICA', 'ica_withholding', 0.414);

        $this->asignarAlCliente($retefuente);
        $this->asignarAlCliente($reteIca);

        $filas = collect(app(OrderEngine::class)
            ->suggestRetentionsFor($this->cliente, 70588.24, 13411.76))
            ->keyBy('tax_code');

        $this->assertEqualsWithDelta(70588.24, $filas['ZZRET-FTE']['base_amount'], 0.01);
        $this->assertEqualsWithDelta(1764.71, $filas['ZZRET-FTE']['amount'], 0.01);

        $this->assertEqualsWithDelta(70588.24, $filas['ZZRET-ICA']['base_amount'], 0.01);
        $this->assertEqualsWithDelta(292.24, $filas['ZZRET-ICA']['amount'], 0.01);
    }

    /** La regla es una sola, compartida con la factura de venta. */
    public function test_la_regla_de_la_base_es_una_sola(): void
    {
        $this->assertSame(500.0, RetentionBase::para('vat_withholding', 10000, 500));
        $this->assertSame(10000.0, RetentionBase::para('income_withholding', 10000, 500));
        $this->assertSame(10000.0, RetentionBase::para('ica_withholding', 10000, 500));

        // Un tipo desconocido no puede caer en la rama del IVA: retendría una
        // fracción de lo que corresponde y nadie lo notaría.
        $this->assertSame(10000.0, RetentionBase::para(null, 10000, 500));
        $this->assertSame(10000.0, RetentionBase::para('otro', 10000, 500));
    }

    // ------------------------------------------- leer la base a mano

    /** La base escrita a mano se lee como la escribe la gente. */
    public function test_la_base_editada_se_lee_bien(): void
    {
        $leer = new ReflectionMethod(NewOrder::class, 'leerMonto');
        $leer->setAccessible(true);

        $pagina = new NewOrder;

        // Lo que entrega un navegador en español.
        $this->assertSame(70588.24, $leer->invoke($pagina, '70588,24'));

        // Y con separador de miles, en las dos convenciones.
        $this->assertSame(1500000.0, $leer->invoke($pagina, '1.500.000'));
        $this->assertSame(1500000.0, $leer->invoke($pagina, '1,500,000'));
        $this->assertSame(1500.5, $leer->invoke($pagina, '1.500,50'));
        $this->assertSame(1500.5, $leer->invoke($pagina, '1,500.50'));

        // Lo de siempre sigue funcionando.
        $this->assertSame(70588.24, $leer->invoke($pagina, '70588.24'));
        $this->assertSame(70588.24, $leer->invoke($pagina, 70588.24));
        $this->assertSame(0.0, $leer->invoke($pagina, ''));
    }

    // ------------------------------------------------- en la pantalla

    /** Se puede agregar una retención que el cliente no tenía. */
    public function test_se_puede_agregar_una_retencion(): void
    {
        $retefuente = $this->impuesto('ZZRET-FTE', 'income_withholding', 2.5);

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cliente->id)
            ->set('cart', [$this->linea(subtotal: 100000, iva: 19000)])
            ->call('addRetention', $retefuente->id)
            ->assertCount('retentions', 1);
    }

    /** Agregada, se calcula con la base de su tipo. */
    public function test_la_agregada_se_calcula_con_su_base(): void
    {
        $reteIva = $this->impuesto('ZZRET-IVA', 'vat_withholding', 15);

        $componente = Livewire::test(NewOrder::class)
            ->set('customerId', $this->cliente->id)
            ->set('cart', [$this->linea(subtotal: 100000, iva: 19000)])
            ->call('addRetention', $reteIva->id);

        $fila = $componente->get('retentions')[0];

        $this->assertEqualsWithDelta(19000, $fila['base_amount'], 0.01,
            'Una ReteIVA agregada a mano también va sobre el IVA.');
        $this->assertEqualsWithDelta(2850, $fila['amount'], 0.01);
    }

    /** La misma retención no se agrega dos veces. */
    public function test_no_se_agrega_dos_veces_la_misma(): void
    {
        $retefuente = $this->impuesto('ZZRET-FTE', 'income_withholding', 2.5);

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cliente->id)
            ->set('cart', [$this->linea(subtotal: 100000, iva: 19000)])
            ->call('addRetention', $retefuente->id)
            ->call('addRetention', $retefuente->id)
            ->assertCount('retentions', 1);
    }

    /** Quitar una de dos y poder devolverla. */
    public function test_se_puede_restaurar_despues_de_quitar_una(): void
    {
        $this->asignarAlCliente($this->impuesto('ZZRET-FTE', 'income_withholding', 2.5));
        $this->asignarAlCliente($this->impuesto('ZZRET-ICA', 'ica_withholding', 0.414));

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cliente->id)
            ->set('cart', [$this->linea(subtotal: 100000, iva: 19000)])
            ->assertCount('retentions', 2)
            ->call('removeRetention', 0)
            ->assertCount('retentions', 1)
            ->call('restoreRetentions')
            ->assertCount('retentions', 2,
                'Quitar una de dos y no poder devolverla dejaba atascada a la gente.');
    }

    /** Cambiar el carrito recalcula cada base según su tipo. */
    public function test_cambiar_el_carrito_recalcula_cada_base(): void
    {
        $this->asignarAlCliente($this->impuesto('ZZRET-IVA', 'vat_withholding', 15));
        $this->asignarAlCliente($this->impuesto('ZZRET-FTE', 'income_withholding', 2.5));

        $componente = Livewire::test(NewOrder::class)
            ->set('customerId', $this->cliente->id)
            ->set('cart', [$this->linea(subtotal: 100000, iva: 19000)])
            ->call('recomputeRetentionBases');

        $filas = collect($componente->get('retentions'))->keyBy('tax_code');

        $this->assertEqualsWithDelta(19000, $filas['ZZRET-IVA']['base_amount'], 0.01);
        $this->assertEqualsWithDelta(100000, $filas['ZZRET-FTE']['base_amount'], 0.01);
    }

    /** Una base corregida a mano no la pisa el recálculo del carrito. */
    public function test_la_base_corregida_a_mano_no_se_pisa(): void
    {
        $this->asignarAlCliente($this->impuesto('ZZRET-FTE', 'income_withholding', 2.5));

        $componente = Livewire::test(NewOrder::class)
            ->set('customerId', $this->cliente->id)
            ->set('cart', [$this->linea(subtotal: 100000, iva: 19000)])
            ->call('updateRetentionBase', 0, '50000,50')
            ->call('recomputeRetentionBases');

        $fila = $componente->get('retentions')[0];

        $this->assertEqualsWithDelta(50000.50, $fila['base_amount'], 0.01,
            'El vendedor la corrigió por algo: el carrito no puede deshacerlo.');
        $this->assertEqualsWithDelta(1250.01, $fila['amount'], 0.01);
    }

    // --------------------------------------------------------- auxiliares

    private function linea(float $subtotal, float $iva): array
    {
        return [
            'product_id' => 0,
            'code' => 'ZZRET',
            'name' => 'ZZ Producto retenciones',
            'quantity' => 1,
            'price_before_tax' => $subtotal,
            'tax_amount' => $iva,
            'price_at_public' => $subtotal + $iva,
        ];
    }

    private function impuesto(string $codigo, string $tipo, float $tarifa): Tax
    {
        $tax = Tax::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ '.$codigo,
            'type' => $tipo,
            'rate' => $tarifa,
            'applies_to' => 'both',
            'is_active' => true,
        ]);

        $this->limpiar[] = function () use ($tax) {
            DB::table('third_party_retentions')->where('tax_id', $tax->id)->delete();
            DB::table('taxes')->where('id', $tax->id)->delete();
        };

        return $tax;
    }

    private function asignarAlCliente(Tax $tax): void
    {
        $this->cliente->retentionTaxes()->syncWithoutDetaching([$tax->id]);
    }

    private function activarTomaDePedidos(): void
    {
        $original = $this->company->active_modules ?? [];

        if (! in_array('order_taking', $original, true)) {
            $this->company->update(['active_modules' => [...$original, 'order_taking']]);
            app(CurrentCompany::class)->set($this->company->fresh());

            $this->limpiar[] = fn () => $this->company->update(['active_modules' => $original]);
        }
    }
}
