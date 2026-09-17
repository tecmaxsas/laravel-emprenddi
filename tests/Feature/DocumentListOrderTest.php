<?php

namespace Tests\Feature;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El orden de los listados de documentos.
 *
 * `date` es una fecha **sin hora**. Ordenar solo por ella deja empatados a todos
 * los documentos del mismo día, y ahí PostgreSQL devuelve las filas en el orden
 * que le convenga —normalmente el físico en disco, que cambia con cada
 * actualización—. El resultado es un listado con los consecutivos revueltos:
 *
 *     POS-000009, POS-000004, POS-000019, POS-000010, POS-000014
 *
 * No es aleatorio ni es un error de la base: es lo que pasa cuando no se le dice
 * cómo desempatar. Y no se arregla solo porque «casi siempre sale bien»: sale
 * bien hasta que alguien edita una factura vieja y esa fila se mueve al final.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class DocumentListOrderTest extends TestCase
{
    private Company $company;

    private Location $sede;

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

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZORD CLIENTE',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($this->cliente->id)->forceDelete();
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
     * Dentro del mismo día, el consecutivo más alto va primero.
     *
     * Las facturas se crean en un orden distinto del que deben mostrarse, y con
     * ids que no siguen el número: si la prueba las creara ordenadas, pasaría
     * aunque el orden real lo estuviera dando el `id` por casualidad.
     */
    public function test_el_mismo_dia_se_ordena_por_consecutivo_descendente(): void
    {
        $hoy = now()->toDateString();

        // Desordenadas a propósito, como aparecían en la pantalla.
        foreach ([9, 4, 19, 10, 14] as $numero) {
            $this->factura($numero, $hoy);
        }

        $numeros = $this->numerosDelListado();

        $this->assertSame([19, 14, 10, 9, 4], $numeros,
            'Dentro del mismo día el listado tiene que ir del consecutivo más alto al más bajo.');
    }

    /** Y entre días, primero el más reciente. */
    public function test_primero_el_dia_mas_reciente(): void
    {
        $this->factura(1, now()->subDays(2)->toDateString());
        $this->factura(2, now()->subDay()->toDateString());
        $this->factura(3, now()->toDateString());

        $this->assertSame([3, 2, 1], $this->numerosDelListado());
    }

    /**
     * La fecha manda sobre el consecutivo.
     *
     * Una factura vieja con número alto no puede subirse encima de una reciente:
     * el listado se lee por fecha.
     */
    public function test_la_fecha_pesa_mas_que_el_numero(): void
    {
        $this->factura(500, now()->subDays(5)->toDateString());
        $this->factura(1, now()->toDateString());

        $this->assertSame([1, 500], $this->numerosDelListado());
    }

    /**
     * El listado real de Filament, no solo la consulta.
     *
     * Es lo que de verdad ve el usuario: la pantalla podría estar reordenando
     * por su cuenta y la consulta seguiría estando bien.
     */
    public function test_la_pantalla_los_muestra_en_ese_orden(): void
    {
        $hoy = now()->toDateString();

        foreach ([9, 4, 19] as $numero) {
            $this->factura($numero, $hoy);
        }

        Livewire::test(SaleInvoiceResource\Pages\ListSaleInvoices::class)
            ->assertOk()
            ->assertSeeInOrder(['ZZORD-000019', 'ZZORD-000009', 'ZZORD-000004']);
    }

    /**
     * Ningún listado de documentos puede quedarse sin desempate.
     *
     * El fallo estaba en diez pantallas a la vez, porque todas se escribieron
     * copiando la misma línea. Si alguien agrega la undécima con
     * `defaultSort('date', 'desc')` a secas, nace con los consecutivos
     * revueltos y nadie lo nota hasta que un usuario reclama.
     */
    public function test_ningun_listado_ordena_solo_por_fecha(): void
    {
        $culpables = [];

        foreach ($this->archivosPhpDe(app_path('Filament')) as $ruta) {
            $contenido = file_get_contents($ruta);

            if (! str_contains($contenido, "defaultSort('date'")) {
                continue;
            }

            $culpables[] = str_replace(base_path().'/', '', $ruta);
        }

        $this->assertSame([], $culpables,
            "Estos listados ordenan solo por fecha y dentro del mismo día salen revueltos:\n"
            .implode("\n", $culpables));
    }

    // --------------------------------------------------------- auxiliares

    /** @return list<int> */
    private function numerosDelListado(): array
    {
        return SaleInvoice::query()
            ->where('third_party_id', $this->cliente->id)
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->orderByDesc('id')
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function factura(int $numero, string $fecha): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZORD',
            'number' => $numero,
            'invoice_kind' => 'pos',
            'date' => $fecha,
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_POSTED,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => 1000,
            'total' => 1000,
            'net_payable' => 1000,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
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
