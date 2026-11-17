<?php

namespace Tests\Feature;

use App\Filament\SuperAdmin\Resources\CompanyResource;
use App\Filament\SuperAdmin\Resources\SubscriptionResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ocultar una compañía no es desactivarla ni borrarla: desaparece de los
 * listados del superadmin pero su ficha sigue abriéndose por URL directa y la
 * empresa opera con normalidad.
 *
 * Usa la base de desarrollo y restaura lo que toca en tearDown.
 */
class HiddenCompanyTest extends TestCase
{
    private User $superAdmin;

    private Company $company;

    private bool $orig;

    private User $usuarioQueOpera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::query()->where('is_super_admin', true)->first();

        if (! $this->superAdmin) {
            $this->markTestSkipped('Sin superadmin en dev.');
        }

        $this->company = $this->unaEmpresaQueOpera();
        $this->orig = (bool) $this->company->hidden_from_admin;
        $this->actingAs($this->superAdmin);

        // En tests el panel activo por defecto es 'app'; sin esto las tablas
        // del superadmin generan rutas de otro panel.
        Filament::setCurrentPanel(Filament::getPanel('super-admin'));
    }

    protected function tearDown(): void
    {
        $this->company->update(['hidden_from_admin' => $this->orig]);
        parent::tearDown();
    }

    public function test_desaparece_del_listado_de_companias_al_ocultarla(): void
    {
        $this->company->update(['hidden_from_admin' => false]);
        Livewire::test(CompanyResource\Pages\ListCompanies::class)
            ->assertCanSeeTableRecords([$this->company]);

        $this->company->update(['hidden_from_admin' => true]);
        Livewire::test(CompanyResource\Pages\ListCompanies::class)
            ->assertCanNotSeeTableRecords([$this->company]);
    }

    public function test_la_ficha_sigue_accesible_por_url_directa(): void
    {
        $this->company->update(['hidden_from_admin' => true]);

        $this->get(CompanyResource::getUrl('edit', ['record' => $this->company]))
            ->assertSuccessful();
    }

    public function test_sus_suscripciones_tampoco_se_listan(): void
    {
        $sub = $this->company->subscriptions()->first();

        if (! $sub) {
            $this->markTestSkipped('La compañía de dev no tiene suscripciones.');
        }

        $this->company->update(['hidden_from_admin' => false]);
        Livewire::test(SubscriptionResource\Pages\ListSubscriptions::class)
            ->assertCanSeeTableRecords([$sub]);

        $this->company->update(['hidden_from_admin' => true]);
        Livewire::test(SubscriptionResource\Pages\ListSubscriptions::class)
            ->assertCanNotSeeTableRecords([$sub]);
    }

    public function test_ocultar_no_afecta_la_operacion_de_la_empresa(): void
    {
        $this->company->update(['hidden_from_admin' => true]);

        $this->actingAs($this->usuarioQueOpera)->get('/app')->assertSuccessful();
    }

    /**
     * Una empresa de la base de desarrollo que de verdad pueda operar.
     *
     * Antes era `Company::query()->firstOrFail()`, y ahí estaba el problema:
     * Postgres no promete NINGÚN orden sin un ORDER BY, así que «la primera»
     * es la que el motor tenga más a mano, y eso cambia con cada inserción o
     * actualización que mueva filas de sitio. La prueba elegía una empresa
     * distinta según el día y pasaba o fallaba sin que nadie tocara el
     * código —y un fallo así se investiga como si fuera una regresión—.
     *
     * Ahora se elige a propósito: la de menor id que esté activa, con
     * suscripción vigente y con un usuario que pueda entrar al panel. Si no
     * hay ninguna, la prueba se salta en vez de fallar: lo que se quiere
     * comprobar es que OCULTAR no cambia nada, y sin una empresa que ya
     * opere no hay nada que comprobar.
     */
    private function unaEmpresaQueOpera(): Company
    {
        $panel = Filament::getPanel('app');

        foreach (Company::query()->where('active', true)->orderBy('id')->get() as $empresa) {
            $usuario = User::query()
                ->where('company_id', $empresa->id)
                ->where('is_super_admin', false)
                ->where('active', true)
                ->orderBy('id')
                ->first();

            if ($usuario && $usuario->canAccessPanel($panel)) {
                $this->usuarioQueOpera = $usuario;

                return $empresa;
            }
        }

        $this->markTestSkipped('En dev no hay ninguna empresa activa con un usuario que pueda entrar.');
    }
}
