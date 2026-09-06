<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\Company;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Alta y búsqueda de clientes desde el POS de retail.
 *
 * El cajero tiene la fila esperando: si para facturarle a un cliente
 * identificado hay que salir del POS, no se hace y todo sale a consumidor
 * final. Por eso se busca y se crea sin salir de la venta.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PosCustomerTest extends TestCase
{
    private int $companyId;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->firstOrFail();
        $this->companyId = (int) $user->company_id;
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set(Company::find($this->companyId));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    public function test_crea_un_cliente_con_los_cuatro_datos_obligatorios(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        $pos = Livewire::test(PosTerminal::class)
            ->set('newCustomerName', 'ANDREA LOPEZ')
            ->set('newCustomerDocumentType', 'cc')
            ->set('newCustomerDocument', $documento)
            ->set('newCustomerEmail', 'andrea@correo.co')
            ->call('createQuickCustomer');

        $cliente = ThirdParty::query()->where('document_number', $documento)->first();
        $this->recoger($cliente);

        $this->assertNotNull($cliente, 'No se creó el cliente.');
        $this->assertSame('cc', $cliente->document_type);
        $this->assertSame('andrea@correo.co', $cliente->email);
        $this->assertTrue((bool) $cliente->is_customer);
        $this->assertSame('natural', $cliente->person_type);

        // Queda seleccionado para la venta en curso.
        $this->assertSame($cliente->id, $pos->get('customer_id'));
        $this->assertFalse($pos->get('showCustomerModal'));
    }

    /** El correo es obligatorio: la factura electrónica se le envía ahí. */
    public function test_no_se_crea_sin_correo(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        Livewire::test(PosTerminal::class)
            ->set('newCustomerName', 'SIN CORREO')
            ->set('newCustomerDocument', $documento)
            ->call('createQuickCustomer');

        $this->assertNull(ThirdParty::query()->where('document_number', $documento)->first());
    }

    public function test_no_se_crea_con_un_correo_invalido(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        Livewire::test(PosTerminal::class)
            ->set('newCustomerName', 'CORREO MALO')
            ->set('newCustomerDocument', $documento)
            ->set('newCustomerEmail', 'esto-no-es-un-correo')
            ->call('createQuickCustomer');

        $this->assertNull(ThirdParty::query()->where('document_number', $documento)->first());
    }

    /**
     * El NIT lleva dígito de verificación y la DIAN lo valida. Se calcula en
     * vez de pedirlo: es una cuenta, no un dato que el cajero deba saber.
     */
    public function test_el_nit_queda_con_su_digito_de_verificacion(): void
    {
        $pos = Livewire::test(PosTerminal::class)
            ->set('newCustomerName', 'COMERCIAL ZZ S.A.S.')
            ->set('newCustomerDocumentType', 'nit')
            ->set('newCustomerDocument', '901043769')
            ->set('newCustomerEmail', 'facturacion@zz.co')
            ->call('createQuickCustomer');

        $cliente = ThirdParty::query()->where('document_number', '901043769')->first();
        $this->recoger($cliente);

        $this->assertNotNull($cliente);
        $this->assertNotNull($cliente->dv, 'Un NIT sin DV lo rechaza la DIAN.');
        $this->assertSame('juridica', $cliente->person_type);
        $this->assertSame($cliente->id, $pos->get('customer_id'));
    }

    /**
     * Un documento repetido no crea un duplicado ni pisa lo que ya estaba: el
     * cliente registrado puede tener más datos de los que caben en una fila
     * del POS.
     */
    public function test_un_documento_repetido_selecciona_al_que_ya_existe(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        $original = ThirdParty::create([
            'company_id' => $this->companyId,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $documento,
            'name' => 'NOMBRE ORIGINAL',
            'email' => 'original@correo.co',
            'address' => 'CALLE ORIGINAL',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->recoger($original);

        $pos = Livewire::test(PosTerminal::class)
            ->set('newCustomerName', 'NOMBRE NUEVO')
            ->set('newCustomerDocument', $documento)
            ->set('newCustomerEmail', 'nuevo@correo.co')
            ->call('createQuickCustomer');

        $this->assertSame(1, ThirdParty::query()->where('document_number', $documento)->count());
        $this->assertSame('NOMBRE ORIGINAL', $original->fresh()->name);
        $this->assertSame('CALLE ORIGINAL', $original->fresh()->address);
        $this->assertSame($original->id, $pos->get('customer_id'));
    }

    public function test_busca_un_cliente_por_nombre_documento_o_correo(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        $cliente = ThirdParty::create([
            'company_id' => $this->companyId,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $documento,
            'name' => 'ZZBUSCABLE PEREZ',
            'email' => 'zzbuscable@correo.co',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->recoger($cliente);

        foreach (['ZZBUSCABLE', $documento, 'zzbuscable@correo.co'] as $termino) {
            $encontrados = Livewire::test(PosTerminal::class)
                ->set('customerSearch', $termino)
                ->get('customerMatches');

            $this->assertTrue($encontrados->contains('id', $cliente->id),
                "No lo encontró buscando por '{$termino}'.");
        }
    }

    /** Dos letras traen medio directorio: no vale la pena consultarlo. */
    public function test_la_busqueda_ignora_terminos_muy_cortos(): void
    {
        $this->assertTrue(
            Livewire::test(PosTerminal::class)->set('customerSearch', 'ab')->get('customerMatches')->isEmpty()
        );
    }

    /** Elegir uno de la lista lo deja listo para la venta. */
    public function test_seleccionar_un_cliente_lo_deja_en_la_venta(): void
    {
        $cliente = ThirdParty::create([
            'company_id' => $this->companyId,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ SELECCIONABLE',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->recoger($cliente);

        $pos = Livewire::test(PosTerminal::class)
            ->set('showCustomerModal', true)
            ->set('customerSearch', 'ZZ SELECCIONABLE')
            ->call('selectCustomer', $cliente->id);

        $this->assertSame($cliente->id, $pos->get('customer_id'));
        $this->assertFalse($pos->get('showCustomerModal'));
        $this->assertSame('', $pos->get('customerSearch'), 'El buscador debe quedar limpio.');
    }

    private function recoger(?ThirdParty $cliente): void
    {
        if ($cliente) {
            $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
                ->whereKey($cliente->id)->forceDelete();
        }
    }
}
