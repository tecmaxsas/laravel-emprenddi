<?php

namespace Tests\Feature;

use App\Filament\App\Resources\ProductCatalogResource;
use App\Filament\App\Resources\ProductCatalogResource\Pages\CreateProductCatalog;
use App\Filament\App\Resources\ProductCatalogResource\Pages\ListProductCatalogs;
use App\Models\Company;
use App\Models\ProductCatalog;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La pantalla del catálogo público dentro del panel.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ProductCatalogResourceTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<int> */
    private array $creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->can('products.manage'))
            ?? $this->markTestSkipped('Ningún usuario de la base puede administrar productos.');

        $this->company = Company::findOrFail($this->user->company_id);

        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    protected function tearDown(): void
    {
        if ($this->creados !== []) {
            DB::table('product_catalogs')->whereIn('id', $this->creados)->delete();
            $this->creados = [];
        }

        parent::tearDown();
    }

    /** El listado abre para quien administra productos. */
    public function test_el_listado_abre(): void
    {
        Livewire::test(ListProductCatalogs::class)->assertOk();
    }

    /**
     * Crear un catálogo deja el tema completo aunque el usuario ni abra la
     * pestaña de diseño. Un tema con huecos rompe la vista pública, que lee las
     * claves sin guardas.
     */
    public function test_al_crear_el_tema_queda_completo(): void
    {
        $slug = 'zz-catalogo-'.random_int(100000, 999999);

        Livewire::test(CreateProductCatalog::class)
            ->set('data.name', 'ZZ Catálogo nuevo')
            ->set('data.slug', $slug)
            ->call('create')
            ->assertHasNoFormErrors();

        $catalogo = ProductCatalog::withoutGlobalScopes()->where('slug', $slug)->first();
        $this->assertNotNull($catalogo, 'No se creó el catálogo.');
        $this->creados[] = $catalogo->id;

        $this->assertSame($this->company->id, $catalogo->company_id);
        $this->assertSame($this->user->id, $catalogo->created_by_user_id);

        foreach (array_keys(ProductCatalog::DEFAULT_THEME) as $clave) {
            $this->assertArrayHasKey($clave, $catalogo->theme,
                "El tema guardado no trae {$clave} y la vista pública lo usa sin guardas.");
        }
    }

    /** El enlace que se comparte apunta a la ruta pública. */
    public function test_la_url_publica_apunta_a_la_ruta_del_catalogo(): void
    {
        $catalogo = ProductCatalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'ZZ Catálogo enlace',
            'slug' => 'zz-enlace-'.random_int(100000, 999999),
            'active' => true,
        ]);
        $this->creados[] = $catalogo->id;

        $this->assertSame(route('catalog.public', $catalogo->slug), $catalogo->publicUrl());
        $this->assertStringContainsString('/catalogo/'.$catalogo->slug, $catalogo->publicUrl());
    }

    /** Dos catálogos no pueden compartir enlace, ni siquiera de empresas distintas. */
    public function test_el_enlace_es_unico_en_toda_la_plataforma(): void
    {
        $slug = 'zz-repetido-'.random_int(100000, 999999);

        $primero = ProductCatalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'ZZ Primero',
            'slug' => $slug,
            'active' => true,
        ]);
        $this->creados[] = $primero->id;

        Livewire::test(CreateProductCatalog::class)
            ->set('data.name', 'ZZ Segundo')
            ->set('data.slug', $slug)
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    /** Sin permiso de productos no se ve la sección. */
    public function test_sin_permiso_de_productos_no_se_ve_la_seccion(): void
    {
        $sinPermiso = User::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'ZZ Usuario sin productos',
            'email' => 'zz-sin-productos-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('ZZclaveDePrueba123'),
            'active' => true,
        ]);

        $this->actingAs($sinPermiso);

        try {
            $this->assertFalse(ProductCatalogResource::canAccess());
        } finally {
            DB::table('audit_logs')->where('auditable_type', User::class)
                ->where('auditable_id', $sinPermiso->id)->delete();
            DB::table('users')->where('id', $sinPermiso->id)->delete();
        }
    }
}
