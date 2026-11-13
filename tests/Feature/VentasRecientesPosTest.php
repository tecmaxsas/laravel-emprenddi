<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Las ventas recientes dentro del POS.
 *
 * Al cajero se le pide reimprimir un tiquete varias veces al día —el cliente
 * lo botó, la impresora se trabó, lo pide para la empresa— y hasta ahora eso
 * significaba salir del POS, irse al listado de facturas y buscarla por
 * número. Con el cliente en el mostrador eso no pasa: se deja para después, y
 * después nadie lo hace.
 *
 * **Son las ventas del TURNO, no las del día.** Lo que el cajero puede
 * reimprimir o corregir es lo que él mismo acaba de vender; lo de otro turno
 * se busca en el listado de facturas, que es donde corresponde, y además
 * tocarlo significaría mover un arqueo que ya firmó alguien.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class VentasRecientesPosTest extends TestCase
{
    use FuerzaPosDeRetail;

    private Company $empresa;

    private User $usuario;

    private Location $sede;

    private Product $producto;

    private ThirdParty $cliente;

    private CashRegisterSession $turno;

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
        $this->prepararCatalogo();
        $this->turno = $this->turnoAbierto();
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

    /** Lista las ventas del turno, de la más reciente a la más vieja. */
    public function test_lista_las_ventas_del_turno(): void
    {
        $primera = $this->venta(50000);
        $segunda = $this->venta(80000);

        $componente = Livewire::test(PosTerminal::class);
        $recientes = $componente->instance()->ventasRecientes;

        $this->assertSame([$segunda->id, $primera->id], $recientes->pluck('id')->all(),
            'La última venta es la que el cajero va a necesitar primero.');
    }

    /**
     * No muestra las de otro turno.
     *
     * Corregir una venta de un turno cerrado movería un arqueo que alguien ya
     * firmó.
     */
    public function test_no_muestra_las_de_otro_turno(): void
    {
        $ajena = $this->venta(90000);

        // La saco de este turno: ahora pertenece a otro.
        DB::table('sale_invoices')->where('id', $ajena->id)
            ->update(['cash_register_session_id' => null]);

        $componente = Livewire::test(PosTerminal::class);

        $this->assertSame([], $componente->instance()->ventasRecientes->pluck('id')->all());
    }

    /** Reimprimir dispara el mismo puente que imprime al cobrar. */
    public function test_reimprimir_usa_el_mismo_camino_que_el_cobro(): void
    {
        $venta = $this->venta(60000);

        Livewire::test(PosTerminal::class)
            ->call('reimprimirVenta', $venta->id)
            ->assertDispatched('pos-print-ticket');
    }

    /** Y no se reimprime una venta que no es de este turno. */
    public function test_no_reimprime_una_venta_ajena(): void
    {
        $venta = $this->venta(60000);
        DB::table('sale_invoices')->where('id', $venta->id)
            ->update(['cash_register_session_id' => null]);

        Livewire::test(PosTerminal::class)
            ->call('reimprimirVenta', $venta->id)
            ->assertNotDispatched('pos-print-ticket');
    }

    /**
     * Corregir carga la venta en el carrito, aquí mismo.
     *
     * Antes mandaba al formulario completo de facturación y dejaba la venta
     * en borrador por adelantado. Las dos cosas cambiaron: una venta del
     * mostrador se corrige en el mostrador, y la factura no se desarma hasta
     * que el cajero vuelve a cobrar —si deja la corrección a medias, que es
     * lo normal cuando entra el siguiente cliente, la venta sigue intacta—.
     *
     * El recorrido completo vive en CorregirVentaEnElPosTest; aquí solo se
     * comprueba que el botón de ventas recientes llega hasta ahí.
     */
    public function test_corregir_carga_la_venta_en_el_carrito(): void
    {
        $venta = $this->venta(70000);

        $pos = Livewire::test(PosTerminal::class)->call('corregirVenta', $venta->id);

        $this->assertSame($venta->id, $pos->instance()->corrigiendo_factura_id);
        $this->assertNotSame([], $pos->instance()->cart, 'El carrito queda cargado con sus líneas.');
        $this->assertTrue($venta->fresh()->isPosted(),
            'Cargarla no la desarma: si quedara en borrador, una corrección abandonada '
            .'dejaría la venta fuera del arqueo sin que nadie se entere.');
    }

    /**
     * Sin el permiso, no corrige.
     *
     * Con un usuario PROPIO, no quitándole los roles al compartido: quitarle
     * todos los roles lo deja sin `pos.use` y el POS lo expulsa antes de
     * llegar a la prueba —se mide quién corrige, no quién entra—. Y tocar los
     * roles del usuario que usan las otras novecientas pruebas ya nos dejó un
     * administrador enganchado a un rol plantilla.
     */
    public function test_corregir_exige_su_permiso(): void
    {
        $venta = $this->venta(70000);

        $cajero = $this->cajeroSinCorregir();
        $this->actingAs($cajero);

        Livewire::test(PosTerminal::class)->call('corregirVenta', $venta->id);

        $this->assertTrue($venta->fresh()->isPosted(), 'Sin permiso la venta no se toca.');
    }

    // --------------------------------------------------------- auxiliares

    private function venta(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'cash_register_session_id' => $this->turno->id,
            'prefix' => 'ZZREC',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_price' => $total,
            'cost_at_sale' => 0,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->empresa->id)
                ->where(fn ($q) => $q->where('reference', 'like', '%ZZREC%')
                    ->orWhere('description', 'like', '%ZZREC%'))
                ->pluck('id');
            DB::table('payments')->where('paymentable_id', $factura->id)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };

        return app(SaleInvoiceEngine::class)
            ->post($factura->fresh(['lines']), allowNegativeStock: true);
    }

    /** Un usuario que puede usar el POS pero no corregir ventas. */
    private function cajeroSinCorregir(): User
    {
        $rol = Role::query()
            ->where('company_id', $this->empresa->id)
            ->where('name', 'CAJERO')
            ->first();

        $this->assertNotNull($rol, 'La empresa de pruebas necesita un rol CAJERO propio.');
        $this->assertFalse($rol->hasPermissionTo('sales.edit_posted'),
            'El cajero no debería poder corregir ventas contabilizadas.');

        $cajero = User::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'ZZREC Cajero',
            'email' => 'zzrec'.random_int(100000, 999999).'@pruebas.local',
            'password' => 'no-se-usa-'.random_int(1000, 9999),
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($cajero) {
            DB::table('model_has_roles')->where('model_id', $cajero->id)
                ->where('model_type', User::class)->delete();
            DB::table('users')->where('id', $cajero->id)->delete();
        };

        $cajero->assignRole($rol);

        return $cajero->fresh();
    }

    private function turnoAbierto(): CashRegisterSession
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

        return $turno;
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZREC'.random_int(100000, 999999),
            'name' => 'ZZREC Cliente recientes',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZREC'.random_int(10000, 99999),
            'name' => 'ZZREC Producto recientes',
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 50000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }
}
