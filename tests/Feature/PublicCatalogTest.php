<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCatalog;
use App\Models\User;
use App\Services\Catalog\CatalogProducts;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El catálogo público de productos.
 *
 * El riesgo grande de esta funcionalidad es el aislamiento: la ruta no tiene
 * autenticación, y `CompanyScope` falla ABIERTO cuando no puede resolver una
 * empresa —sin usuario no filtra nada—. Una consulta escrita como cualquier
 * otra del sistema publicaría los productos de todos los clientes en el enlace
 * de uno. Varias de estas pruebas existen solo para eso.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PublicCatalogTest extends TestCase
{
    private Company $company;

    private ProductCatalog $catalogo;

    private Product $producto;

    private Category $categoria;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        app(CurrentCompany::class)->set($this->company);

        $this->prepararCatalogo();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** El enlace abre sin sesión y muestra los productos. */
    public function test_el_catalogo_abre_sin_iniciar_sesion(): void
    {
        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertSee($this->catalogo->name)
            ->assertSee($this->producto->name);
    }

    /**
     * El caso que justifica media clase: sin usuario, el scope de empresa no
     * filtra. Si la consulta no lo hace a mano, el catálogo de un cliente
     * muestra el inventario de otro.
     */
    public function test_no_se_ven_productos_de_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base: no hay con qué cruzar.');
        }

        $ajeno = Product::withoutGlobalScopes()->create([
            'company_id' => $otra->id,
            'code' => 'ZZAJENO'.random_int(10000, 99999),
            'name' => 'ZZ PRODUCTO DE OTRA EMPRESA',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 99000,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($ajeno->id)->forceDelete();

        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertSee($this->producto->name)
            ->assertDontSee('ZZ PRODUCTO DE OTRA EMPRESA');
    }

    /** Apagar el catálogo cierra el enlace sin borrar la configuración. */
    public function test_un_catalogo_apagado_responde_404(): void
    {
        $this->catalogo->update(['active' => false]);

        $this->get('/catalogo/'.$this->catalogo->slug)->assertNotFound();
    }

    public function test_un_enlace_inexistente_responde_404(): void
    {
        $this->get('/catalogo/no-existe-este-catalogo')->assertNotFound();
    }

    /** Sin precios, el catálogo sirve de vitrina y el cliente pregunta. */
    public function test_se_pueden_ocultar_los_precios(): void
    {
        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertSee('185.000');

        $this->catalogo->update(['show_prices' => false]);

        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertSee($this->producto->name)
            ->assertDontSee('185.000');
    }

    /** Publicar solo unas categorías deja fuera el resto del inventario. */
    public function test_el_filtro_de_categorias_deja_fuera_lo_demas(): void
    {
        $otraCategoria = Category::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZCAT'.random_int(1000, 9999),
            'name' => 'ZZ Categoría excluida',
            'slug' => 'zz-categoria-excluida-'.random_int(1000, 9999),
            'active' => true,
        ]);
        $this->limpiar[] = fn () => Category::withoutGlobalScopes()->whereKey($otraCategoria->id)->forceDelete();

        $excluido = $this->crearProducto('ZZ PRODUCTO EXCLUIDO', 50000, $otraCategoria->id);

        $this->catalogo->update(['category_ids' => [$this->categoria->id]]);

        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertSee($this->producto->name)
            ->assertDontSee($excluido->name);
    }

    /** Un producto inactivo desaparece del enlace de inmediato. */
    public function test_desactivar_un_producto_lo_saca_del_catalogo(): void
    {
        $this->get('/catalogo/'.$this->catalogo->slug)->assertSee($this->producto->name);

        $this->producto->update(['active' => false]);

        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertDontSee($this->producto->name);
    }

    /** La búsqueda encuentra por nombre y por código. */
    public function test_la_busqueda_filtra_por_nombre_y_codigo(): void
    {
        $otro = $this->crearProducto('ZZ OTRO ARTICULO', 30000, $this->categoria->id);

        $this->get('/catalogo/'.$this->catalogo->slug.'?q='.urlencode($this->producto->name))
            ->assertOk()
            ->assertSee($this->producto->name)
            ->assertDontSee($otro->name);

        $this->get('/catalogo/'.$this->catalogo->slug.'?q='.$otro->code)
            ->assertOk()
            ->assertSee($otro->name);
    }

    /**
     * Las variantes no se listan sueltas: «Camiseta talla S», «talla M» y
     * «talla L» se verían como tres productos distintos. Se muestra el padre.
     */
    public function test_las_variantes_no_se_listan_como_productos_aparte(): void
    {
        $padre = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZVAR'.random_int(10000, 99999),
            'name' => 'ZZ CAMISETA BASE',
            'type' => 'variable',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => false,
            'category_id' => $this->categoria->id,
            'default_sale_price' => 0,
            'active' => true,
        ]);

        $variante = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'parent_product_id' => $padre->id,
            'code' => 'ZZVAR'.random_int(10000, 99999).'-M',
            'name' => 'ZZ CAMISETA TALLA M',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'category_id' => $this->categoria->id,
            'default_sale_price' => 60000,
            'active' => true,
        ]);

        $segunda = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'parent_product_id' => $padre->id,
            'code' => 'ZZVAR'.random_int(10000, 99999).'-L',
            'name' => 'ZZ CAMISETA TALLA L',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'category_id' => $this->categoria->id,
            'default_sale_price' => 72000,
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($padre, $variante, $segunda) {
            Product::withoutGlobalScopes()->whereKey($variante->id)->forceDelete();
            Product::withoutGlobalScopes()->whereKey($segunda->id)->forceDelete();
            Product::withoutGlobalScopes()->whereKey($padre->id)->forceDelete();
        };

        $html = $this->get('/catalogo/'.$this->catalogo->slug)->assertOk()->getContent();

        // Dos tarjetas, no tres: el perfume del setUp y la camiseta padre.
        $this->assertSame(2, substr_count($html, '<article class="tarjeta">'),
            'Cada variante estaría apareciendo como un producto suelto.');

        $this->assertStringContainsString('ZZ CAMISETA BASE', $html);

        // El nombre de la variante sí sale, pero dentro de la tarjeta del
        // padre: «1 presentaciones: ZZ CAMISETA TALLA M». Eso es lo que se
        // quiere —el cliente tiene que saber qué tallas hay—.
        $this->assertStringContainsString('2 presentaciones', $html);

        // El padre no tiene precio propio: se muestra el rango de sus variantes.
        $this->assertStringContainsString('60.000', $html);
        $this->assertStringContainsString('72.000', $html);
    }

    /** Por defecto el enlace no se indexa: se comparte, no se publica. */
    public function test_por_defecto_pide_a_los_buscadores_que_no_lo_indexen(): void
    {
        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertSee('noindex', escape: false);

        $this->catalogo->update(['allow_indexing' => true]);

        $this->get('/catalogo/'.$this->catalogo->slug)
            ->assertOk()
            ->assertDontSee('noindex', escape: false);
    }

    /** El número de WhatsApp se normaliza para que wa.me lo acepte. */
    public function test_el_whatsapp_se_normaliza_con_indicativo(): void
    {
        $this->catalogo->whatsapp = '310 555 1234';
        $this->assertSame('573105551234', $this->catalogo->whatsappNormalizado());

        $this->catalogo->whatsapp = '+57 (310) 555-1234';
        $this->assertSame('573105551234', $this->catalogo->whatsappNormalizado());

        $this->catalogo->whatsapp = '';
        $this->catalogo->contact_phone = '';
        $this->assertNull($this->catalogo->whatsappNormalizado());
    }

    /** Un tema a medio llenar no puede romper la vista pública. */
    public function test_el_tema_se_completa_con_los_valores_de_fabrica(): void
    {
        $this->catalogo->theme = ['primary_color' => '#ff0000'];
        $tema = $this->catalogo->themeCompleto();

        $this->assertSame('#ff0000', $tema['primary_color']);

        foreach (array_keys(ProductCatalog::DEFAULT_THEME) as $clave) {
            $this->assertArrayHasKey($clave, $tema, "Falta {$clave}: la vista lo usa sin guardas.");
        }

        $this->catalogo->theme = null;
        $this->assertSame(ProductCatalog::DEFAULT_THEME, $this->catalogo->themeCompleto());
    }

    /** El conteo del formulario cuenta lo mismo que se publica. */
    public function test_el_conteo_coincide_con_lo_publicado(): void
    {
        $this->crearProducto('ZZ SEGUNDO PRODUCTO', 20000, $this->categoria->id);

        $this->assertSame(2, app(CatalogProducts::class)->total($this->catalogo));

        $this->catalogo->only_with_image = true;

        $this->assertSame(0, app(CatalogProducts::class)->total($this->catalogo),
            'Ninguno de los productos de prueba tiene foto.');
    }

    private function prepararCatalogo(): void
    {
        $this->categoria = Category::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZCAT'.random_int(1000, 9999),
            'name' => 'ZZ Categoría del catálogo',
            'slug' => 'zz-categoria-catalogo-'.random_int(1000, 9999),
            'active' => true,
        ]);

        $this->producto = $this->crearProducto('ZZ PERFUME DE PRUEBA', 185000, $this->categoria->id);

        $this->catalogo = ProductCatalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'ZZ Catálogo de prueba',
            'slug' => 'zz-catalogo-'.random_int(100000, 999999),
            'subtitle' => 'Prueba automatizada',
            'show_prices' => true,
            'active' => true,
            'theme' => ProductCatalog::DEFAULT_THEME,
        ]);

        $this->limpiar[] = function () {
            DB::table('product_catalogs')->where('id', $this->catalogo->id)->delete();
            Category::withoutGlobalScopes()->whereKey($this->categoria->id)->forceDelete();
        };
    }

    private function crearProducto(string $nombre, float $precio, ?int $categoriaId): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZCAT'.random_int(10000, 99999),
            'name' => $nombre,
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'category_id' => $categoriaId,
            'default_sale_price' => $precio,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();

        return $producto;
    }
}
