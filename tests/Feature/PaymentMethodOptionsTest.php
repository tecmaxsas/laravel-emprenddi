<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PaymentMethodOptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Las formas de pago que ve el usuario son las de su empresa.
 *
 * Una empresa que cobra por Nequi, Daviplata o un convenio propio los crea en
 * Configuración → Métodos de pago. Si una pantalla no los lee, el cajero no
 * puede registrar el cobro como realmente entró la plata: lo mete como «Otro» y
 * el arqueo deja de cuadrar contra el extracto.
 *
 * La regla estaba copiada en cuatro pantallas y en dos se había quedado atrás:
 * el registro de anticipos del estado de cuenta y el POS de restaurante
 * mostraban solo la lista de fábrica. Una copia que se queda atrás no falla:
 * hace que media empresa trabaje con datos distintos a la otra media.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PaymentMethodOptionsTest extends TestCase
{
    private Company $company;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        PaymentMethodOptions::olvidarCache();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        PaymentMethodOptions::olvidarCache();

        parent::tearDown();
    }

    /** El método propio de la empresa aparece en la lista. */
    public function test_un_metodo_propio_aparece(): void
    {
        $this->metodo('zznequi', 'ZZ Nequi');

        $opciones = PaymentMethodOptions::para($this->company->id);

        $this->assertArrayHasKey('zznequi', $opciones,
            'Sin él, el cajero registra el cobro como «Otro» y el arqueo no cuadra.');
        $this->assertSame('ZZ Nequi', $opciones['zznequi']);
    }

    /** Un método desactivado no se puede elegir. */
    public function test_un_metodo_desactivado_no_aparece(): void
    {
        $this->metodo('zzviejo', 'ZZ Convenio viejo', activo: false);

        $this->assertArrayNotHasKey('zzviejo', PaymentMethodOptions::para($this->company->id));
    }

    /**
     * Una empresa sin métodos configurados no se queda sin poder cobrar.
     *
     * La lista de fábrica es el salvavidas de las empresas recién creadas: sin
     * ella la pantalla saldría vacía.
     */
    public function test_sin_metodos_configurados_quedan_los_de_fabrica(): void
    {
        $conMetodos = DB::table('payment_methods')->distinct()->pluck('company_id')->all();

        $vacia = Company::query()
            ->when($conMetodos, fn ($q) => $q->whereNotIn('id', $conMetodos))
            ->value('id');

        // Si todas las empresas de la base tienen métodos, sirve igual un id que
        // no exista: lo que se mide es qué pasa cuando la consulta no devuelve
        // nada.
        $opciones = PaymentMethodOptions::para($vacia ?? 999999999);

        $this->assertSame(Payment::PAYMENT_METHODS, $opciones,
            'Una empresa recién creada se quedaría sin poder registrar un cobro.');
    }

    /** Los métodos de otra empresa no se cuelan. */
    public function test_no_se_cuelan_los_de_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base de desarrollo.');
        }

        $this->metodo('zzajeno', 'ZZ Método ajeno', companyId: $otra->id);

        $this->assertArrayNotHasKey('zzajeno', PaymentMethodOptions::para($this->company->id));
    }

    // -------------------------------------------------- los nombres

    /**
     * El tiquete imprime el nombre que la empresa le puso.
     *
     * Es la otra mitad del arreglo: al poder elegir códigos propios, un método
     * con código «zznequi» se imprimía así, en crudo, en el recibo del cliente.
     */
    public function test_el_nombre_propio_se_usa_al_imprimir(): void
    {
        $this->metodo('zznequi', 'ZZ Nequi');

        $this->assertSame('ZZ Nequi',
            PaymentMethodOptions::nombre('zznequi', $this->company->id));
    }

    /** Los de fábrica siguen teniendo su nombre de siempre. */
    public function test_los_de_fabrica_conservan_su_nombre(): void
    {
        $this->assertSame('Efectivo', PaymentMethodOptions::nombre('cash', $this->company->id));
        $this->assertSame('Cheque', PaymentMethodOptions::nombre('check', $this->company->id));
    }

    /** Un código que ya no existe se muestra legible, no en blanco. */
    public function test_un_codigo_desconocido_no_queda_en_blanco(): void
    {
        $this->assertSame('Inventado',
            PaymentMethodOptions::nombre('inventado', $this->company->id));

        $this->assertSame('', PaymentMethodOptions::nombre(null, $this->company->id));
    }

    /**
     * Ninguna pantalla puede volver a ofrecer la lista de fábrica a secas.
     *
     * La regla ya se había copiado cuatro veces y dos copias se quedaron atrás.
     * Mientras salgan todas del mismo sitio, arreglarla una vez alcanza.
     */
    public function test_ninguna_pantalla_ofrece_la_lista_de_fabrica_a_secas(): void
    {
        $culpables = [];

        foreach ($this->archivosPhpDe(app_path('Filament')) as $ruta) {
            $contenido = file_get_contents($ruta);

            // Solo `Payment::PAYMENT_METHODS`: `Employee::PAYMENT_METHODS` es
            // otra cosa —como se le paga la nomina a un empleado— y no tiene
            // nada que ver con las formas de cobro de la empresa.
            if (preg_match('/->options\([^)]{0,120}\bPayment::PAYMENT_METHODS/s', $contenido)) {
                $culpables[] = str_replace(base_path().'/', '', $ruta);
            }
        }

        $this->assertSame([], $culpables,
            "Estas pantallas ofrecen las formas de pago de fábrica en vez de las de la empresa:\n"
            .implode("\n", $culpables));
    }

    // --------------------------------------------------------- auxiliares

    private function metodo(string $codigo, string $nombre, bool $activo = true, ?int $companyId = null): PaymentMethod
    {
        $metodo = PaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $companyId ?? $this->company->id,
            'code' => $codigo,
            'name' => $nombre,
            'type' => 'cash',
            'active' => $activo,
            'sort_order' => 99,
        ]);

        $this->limpiar[] = fn () => DB::table('payment_methods')->where('id', $metodo->id)->delete();

        PaymentMethodOptions::olvidarCache();

        return $metodo;
    }

    /** @return list<string> */
    private function archivosPhpDe(string $directorio): array
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS)
        );

        $rutas = [];

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && str_ends_with($archivo->getFilename(), '.php')) {
                $rutas[] = $archivo->getPathname();
            }
        }

        return $rutas;
    }
}
