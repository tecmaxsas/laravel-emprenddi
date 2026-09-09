<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Reports\CustomerStatementPage;
use App\Models\Company;
use App\Models\CustomerStatementShare;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Compartir el estado de cuenta por WhatsApp.
 *
 * Lo que se manda es un enlace, no un PDF: se abre en el celular sin descargar
 * nada y muestra el saldo del día en que se abre. Eso trae dos riesgos que estas
 * pruebas cuidan:
 *
 *  1. Es una ruta pública con información financiera de un tercero. El token no
 *     se puede adivinar, caduca, y no puede mostrar datos de otra empresa —sin
 *     usuario, `CompanyScope` no filtra nada—.
 *  2. El número de destino lo escribe una persona con prisa. Tiene que
 *     normalizarse solo o rechazarse, nunca abrir un chat equivocado.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CustomerStatementWhatsappTest extends TestCase
{
    private Company $company;

    private User $user;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->can('reports.accounts_receivable'))
            ?? $this->markTestSkipped('Ningún usuario de la base puede ver la cartera.');

        $this->company = Company::findOrFail($this->user->company_id);

        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE WHATSAPP',
            'mobile' => '3105551234',
            'phone' => '6012223344',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('customer_statement_shares')->where('third_party_id', $this->cliente->id)->delete();
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

    /** Compartir crea el enlace y deja registrado a qué número se mandó. */
    public function test_compartir_genera_un_enlace_para_ese_cliente(): void
    {
        Livewire::test(CustomerStatementPage::class)
            ->set('filters.third_party_id', $this->cliente->id)
            ->call('shareByWhatsapp', ['phone' => '3105551234', 'days' => 30])
            ->assertOk();

        $enlace = CustomerStatementShare::withoutGlobalScopes()
            ->where('third_party_id', $this->cliente->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($enlace, 'No se creó el enlace.');
        $this->assertSame($this->company->id, $enlace->company_id);
        $this->assertSame('573105551234', $enlace->sent_to);
        $this->assertSame(40, strlen($enlace->token), 'Un token corto se puede adivinar.');
        $this->assertTrue($enlace->vigente());
        $this->assertEqualsWithDelta(30, now()->diffInDays($enlace->expires_at), 1);
    }

    /** El cliente abre el enlace sin sesión y ve su cuenta. */
    public function test_el_cliente_abre_el_enlace_sin_iniciar_sesion(): void
    {
        $enlace = $this->crearEnlace();

        $this->get($enlace->publicUrl())
            ->assertOk()
            ->assertSee($this->cliente->name)
            ->assertSee('Estado de cuenta', escape: false);
    }

    /** Abrirlo queda registrado: sirve para saber si el cliente lo vio. */
    public function test_se_registra_cuando_el_cliente_lo_abre(): void
    {
        $enlace = $this->crearEnlace();

        $this->assertSame(0, $enlace->view_count);
        $this->assertNull($enlace->last_viewed_at);

        $this->get($enlace->publicUrl())->assertOk();
        $this->get($enlace->publicUrl())->assertOk();

        $enlace->refresh();

        $this->assertSame(2, $enlace->view_count);
        $this->assertNotNull($enlace->last_viewed_at);
    }

    /** Un enlace vencido deja de funcionar: no queda vivo en un chat reenviado. */
    public function test_un_enlace_vencido_responde_404(): void
    {
        $enlace = $this->crearEnlace();
        $enlace->update(['expires_at' => now()->subDay()]);

        $this->get($enlace->publicUrl())->assertNotFound();
    }

    /** Un token inventado no llega a ninguna parte. */
    public function test_un_token_inventado_responde_404(): void
    {
        $this->get('/estado-cuenta/'.str_repeat('a', 40))->assertNotFound();
    }

    /**
     * Sin usuario, `CompanyScope` no filtra. Si la consulta no lleva la empresa
     * a mano, un enlace mostraría movimientos de otro cliente.
     */
    public function test_el_enlace_solo_muestra_los_datos_de_su_cliente(): void
    {
        $otro = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ OTRO CLIENTE DISTINTO',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()->whereKey($otro->id)->forceDelete();

        $this->get($this->crearEnlace()->publicUrl())
            ->assertOk()
            ->assertSee($this->cliente->name)
            ->assertDontSee('ZZ OTRO CLIENTE DISTINTO');
    }

    /** El número se sugiere del tercero, prefiriendo el celular. */
    public function test_se_sugiere_el_celular_del_tercero(): void
    {
        $componente = Livewire::test(CustomerStatementPage::class)
            ->set('filters.third_party_id', $this->cliente->id);

        $this->assertSame('3105551234', $componente->instance()->defaultWhatsappNumber(),
            'Debe proponer el celular, no el fijo.');
    }

    /** Un celular colombiano de diez dígitos recibe su indicativo solo. */
    public function test_el_numero_se_normaliza_para_wame(): void
    {
        $casos = [
            '3105551234' => '573105551234',
            '310 555 1234' => '573105551234',
            '+57 (310) 555-1234' => '573105551234',
            '6012223344' => '6012223344',     // fijo: no se le inventa indicativo
            '13055551234' => '13055551234',   // ya trae indicativo
        ];

        foreach ($casos as $escrito => $esperado) {
            Livewire::test(CustomerStatementPage::class)
                ->set('filters.third_party_id', $this->cliente->id)
                ->call('shareByWhatsapp', ['phone' => $escrito, 'days' => 7]);

            $this->assertSame($esperado, CustomerStatementShare::withoutGlobalScopes()
                ->where('third_party_id', $this->cliente->id)
                ->latest('id')
                ->value('sent_to'), "Falló con «{$escrito}».");
        }
    }

    /** Un número imposible no abre un chat equivocado: no genera nada. */
    public function test_un_numero_invalido_no_genera_enlace(): void
    {
        $antes = CustomerStatementShare::withoutGlobalScopes()
            ->where('third_party_id', $this->cliente->id)->count();

        Livewire::test(CustomerStatementPage::class)
            ->set('filters.third_party_id', $this->cliente->id)
            ->call('shareByWhatsapp', ['phone' => '123', 'days' => 30]);

        $this->assertSame($antes, CustomerStatementShare::withoutGlobalScopes()
            ->where('third_party_id', $this->cliente->id)->count());
    }

    /** El mensaje lleva el saldo y el enlace, no solo un «mire su cuenta». */
    public function test_el_mensaje_incluye_el_saldo_y_el_enlace(): void
    {
        $pagina = Livewire::test(CustomerStatementPage::class)
            ->set('filters.third_party_id', $this->cliente->id)
            ->instance();

        $mensaje = $pagina->whatsappMessage($this->cliente, 'https://ejemplo.test/estado-cuenta/abc');

        $this->assertStringContainsString('ZZ', $mensaje, 'Debe saludar por el nombre.');
        $this->assertStringContainsString('https://ejemplo.test/estado-cuenta/abc', $mensaje);
        $this->assertStringContainsString('saldo', mb_strtolower($mensaje));
    }

    private function crearEnlace(): CustomerStatementShare
    {
        return CustomerStatementShare::paraCliente($this->cliente, null, null, '573105551234');
    }
}
