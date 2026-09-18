<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\QuickCustomer;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Crear un cliente desde el POS cuando el documento ya existe.
 *
 * La búsqueda previa solo miraba entre los terceros **vivos**, pero el índice
 * único de la base —(empresa, tipo, número)— cuenta también los eliminados.
 * Un cliente borrado bloqueaba la creación de uno nuevo con ese documento sin
 * que el POS pudiera encontrarlo, y al cajero le salía el SQL crudo del error
 * de clave duplicada en plena fila.
 *
 * El otro caso real es el mismo señor registrado **solo como proveedor**:
 * existe, choca contra el índice, y no sirve como cliente.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class QuickCustomerDuplicadoTest extends TestCase
{
    private Company $company;

    private QuickCustomer $servicio;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->servicio = app(QuickCustomer::class);
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
     * Un cliente eliminado se restaura en vez de reventar.
     *
     * Es el error que salió en producción: «duplicate key value violates
     * unique constraint third_parties_company_id_document_type_document_
     * number_unique», con el SQL completo en pantalla.
     */
    public function test_un_cliente_eliminado_se_restaura(): void
    {
        $original = $this->tercero(['name' => 'ZZ Eliminado']);
        $original->delete();

        $this->assertTrue($original->fresh()->trashed());

        $resultado = $this->servicio->create($this->company->id, $this->datos());

        $this->assertTrue($resultado['existed']);
        $this->assertSame($original->id, $resultado['customer']->id,
            'Es el mismo tercero: crear otro con ese documento es imposible.');
        $this->assertFalse($resultado['customer']->fresh()->trashed());
        $this->assertStringContainsString('eliminado', (string) $resultado['note'],
            'El cajero tiene que enterarse de que se restauró algo.');
    }

    /** Uno que solo era proveedor pasa a ser también cliente. */
    public function test_un_proveedor_pasa_a_ser_tambien_cliente(): void
    {
        $original = $this->tercero([
            'name' => 'ZZ Solo Proveedor',
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $resultado = $this->servicio->create($this->company->id, $this->datos());

        $this->assertSame($original->id, $resultado['customer']->id);
        $this->assertTrue($resultado['customer']->fresh()->is_customer);
        $this->assertTrue($resultado['customer']->fresh()->is_supplier,
            'Seguir siendo proveedor no estorba: es el mismo señor.');
        $this->assertStringContainsString('proveedor', (string) $resultado['note']);
    }

    /** Uno inactivo se reactiva. */
    public function test_un_tercero_inactivo_se_reactiva(): void
    {
        $original = $this->tercero(['name' => 'ZZ Inactivo', 'active' => false]);

        $resultado = $this->servicio->create($this->company->id, $this->datos());

        $this->assertSame($original->id, $resultado['customer']->id);
        $this->assertTrue($resultado['customer']->fresh()->active);
    }

    /**
     * Un cliente que ya estaba bien no se toca y no se avisa de nada.
     *
     * Un mensaje que aparece siempre deja de leerse.
     */
    public function test_un_cliente_normal_no_genera_aviso(): void
    {
        $original = $this->tercero(['name' => 'ZZ Normal']);

        $resultado = $this->servicio->create($this->company->id, $this->datos());

        $this->assertTrue($resultado['existed']);
        $this->assertSame($original->id, $resultado['customer']->id);
        $this->assertNull($resultado['note']);
    }

    /** Los datos del que ya existía no se pisan con lo poco que se digitó. */
    public function test_no_se_pisan_los_datos_del_que_ya_existia(): void
    {
        $original = $this->tercero([
            'name' => 'ZZ Nombre Completo Con Todo',
            'phone' => '3001234567',
        ]);

        $this->servicio->create($this->company->id, [
            'name' => 'zz',
            'document_type' => 'cc',
            'document_number' => $original->document_number,
            'email' => 'otro@ejemplo.com',
        ]);

        $fresco = $original->fresh();

        $this->assertSame('ZZ Nombre Completo Con Todo', $fresco->name,
            'El registro viejo tiene más información de la que cabe digitar en el POS.');
        $this->assertSame('3001234567', $fresco->phone);
    }

    /** Un documento nuevo sí crea el cliente. */
    public function test_un_documento_nuevo_crea_el_cliente(): void
    {
        $documento = (string) random_int(800000000, 899999999);

        $resultado = $this->servicio->create($this->company->id, [
            'name' => 'ZZ Cliente Nuevo',
            'document_type' => 'cc',
            'document_number' => $documento,
            'email' => 'nuevo@ejemplo.com',
        ]);

        $this->limpiar[] = fn () => DB::table('third_parties')
            ->where('id', $resultado['customer']->id)->delete();

        $this->assertFalse($resultado['existed']);
        $this->assertNull($resultado['note']);
        $this->assertTrue($resultado['customer']->is_customer);
    }

    /** Un tercero de otra empresa no se toca. */
    public function test_no_se_cruza_con_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base de desarrollo.');
        }

        $documento = (string) random_int(700000000, 799999999);

        $ajeno = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $otra->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $documento,
            'name' => 'ZZ De Otra Empresa',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => DB::table('third_parties')->where('id', $ajeno->id)->delete();

        $resultado = $this->servicio->create($this->company->id, [
            'name' => 'ZZ Mismo Documento Otra Empresa',
            'document_type' => 'cc',
            'document_number' => $documento,
            'email' => 'mismo@ejemplo.com',
        ]);

        $this->limpiar[] = fn () => DB::table('third_parties')
            ->where('id', $resultado['customer']->id)->delete();

        $this->assertFalse($resultado['existed'],
            'El índice único es por empresa: dos empresas pueden tener el mismo cliente.');
        $this->assertNotSame($ajeno->id, $resultado['customer']->id);
    }

    // --------------------------------------------------------- auxiliares

    /** @return array<string, mixed> */
    private function datos(): array
    {
        return [
            'name' => 'ZZ Desde el POS',
            'document_type' => 'cc',
            'document_number' => $this->documento,
            'email' => 'pos@ejemplo.com',
        ];
    }

    private string $documento = '';

    /** @param  array<string, mixed>  $extra */
    private function tercero(array $extra = []): ThirdParty
    {
        $this->documento = (string) random_int(600000000, 699999999);

        $tercero = ThirdParty::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $this->documento,
            'name' => 'ZZ Tercero',
            'is_customer' => true,
            'is_supplier' => false,
            'active' => true,
        ], $extra));

        $this->limpiar[] = fn () => DB::table('third_parties')->where('id', $tercero->id)->delete();

        return $tercero;
    }
}
