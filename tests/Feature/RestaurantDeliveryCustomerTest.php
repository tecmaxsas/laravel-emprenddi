<?php

namespace Tests\Feature;

use App\Models\Restaurant\Order;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Restaurant\DeliveryCustomerRegistrar;
use App\Services\Restaurant\RestaurantOrderEngine;
use Tests\TestCase;

/**
 * El cliente de un domicilio queda guardado como tercero.
 *
 * Sirve para que la próxima vez se busque y se precargue en vez de volver a
 * dictarlo todo. Lo delicado es el documento: third_parties lo exige y lo
 * quiere único, pero quien pide por teléfono casi nunca da la cédula.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class RestaurantDeliveryCustomerTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    public function test_con_cedula_queda_como_cliente_identificado(): void
    {
        $cedula = 'ZZ'.random_int(100000, 999999);

        $cliente = $this->registrar()->register(
            $this->companyId,
            'JUAN PEREZ',
            documento: $cedula,
            telefono: '3001234567',
            direccion: 'CALLE 10 #25-15',
        );

        $this->recogerBasura($cliente);

        $this->assertNotNull($cliente);
        $this->assertSame($cedula, $cliente->document_number);
        $this->assertFalse((bool) $cliente->is_delivery_contact,
            'Con cédula el cliente está identificado y su documento puede ir a la DIAN.');
        $this->assertTrue((bool) $cliente->is_customer);
        $this->assertSame('CALLE 10 #25-15', $cliente->address);
    }

    /**
     * Sin cédula el teléfono hace de identificador, pero queda marcado: ese
     * número no es un documento y no puede viajar a la DIAN como si lo fuera.
     */
    public function test_sin_cedula_se_guarda_por_telefono_y_queda_marcado(): void
    {
        $telefono = '30'.random_int(10000000, 99999999);

        $cliente = $this->registrar()->register(
            $this->companyId,
            'MARIA GOMEZ',
            telefono: $telefono,
            direccion: 'CARRERA 5 #12-30',
        );

        $this->recogerBasura($cliente);

        $this->assertNotNull($cliente);
        $this->assertSame($telefono, $cliente->document_number);
        $this->assertTrue((bool) $cliente->is_delivery_contact);
    }

    /** Un nombre solo no se puede volver a encontrar sin duplicarlo. */
    public function test_sin_cedula_ni_telefono_no_se_guarda_nada(): void
    {
        $antes = ThirdParty::query()->where('company_id', $this->companyId)->count();

        $cliente = $this->registrar()->register($this->companyId, 'CLIENTE SIN DATOS');

        $this->assertNull($cliente);
        $this->assertSame($antes, ThirdParty::query()->where('company_id', $this->companyId)->count());
    }

    /** El que vuelve a pedir actualiza sus datos, no crea otro registro. */
    public function test_el_cliente_que_vuelve_no_se_duplica(): void
    {
        $cedula = 'ZZ'.random_int(100000, 999999);

        $primero = $this->registrar()->register(
            $this->companyId, 'PEDRO RUIZ', documento: $cedula, direccion: 'CALLE 1',
        );
        $this->recogerBasura($primero);

        $segundo = $this->registrar()->register(
            $this->companyId, 'PEDRO RUIZ', documento: $cedula, direccion: 'CALLE 2 NUEVA',
        );

        $this->assertSame($primero->id, $segundo->id, 'Se creó un cliente duplicado.');
        $this->assertSame('CALLE 2 NUEVA', $segundo->address, 'La dirección nueva debe reemplazar a la anterior.');
    }

    /**
     * El domicilio se toma deprisa y es fácil dejar un campo en blanco. Un
     * vacío no puede borrar lo que ya se sabía del cliente.
     */
    public function test_un_dato_en_blanco_no_borra_el_que_ya_estaba(): void
    {
        $cedula = 'ZZ'.random_int(100000, 999999);

        $primero = $this->registrar()->register(
            $this->companyId, 'ANA DIAZ', documento: $cedula,
            telefono: '3009999999', direccion: 'CALLE ORIGINAL',
        );
        $this->recogerBasura($primero);

        $segundo = $this->registrar()->register($this->companyId, 'ANA DIAZ', documento: $cedula);

        $this->assertSame('3009999999', $segundo->phone);
        $this->assertSame('CALLE ORIGINAL', $segundo->address);
    }

    public function test_se_busca_por_nombre_cedula_o_telefono(): void
    {
        $cedula = 'ZZ'.random_int(100000, 999999);
        $telefono = '31'.random_int(10000000, 99999999);

        $cliente = $this->registrar()->register(
            $this->companyId, 'CARLOS ZZTESTBUSQUEDA', documento: $cedula, telefono: $telefono,
        );
        $this->recogerBasura($cliente);

        $registrar = $this->registrar();

        $this->assertTrue($registrar->search($this->companyId, 'ZZTESTBUSQUEDA')->contains('id', $cliente->id));
        $this->assertTrue($registrar->search($this->companyId, $cedula)->contains('id', $cliente->id));
        $this->assertTrue($registrar->search($this->companyId, $telefono)->contains('id', $cliente->id));
    }

    /** Dos letras traen medio directorio: no vale la pena consultarlo. */
    public function test_la_busqueda_ignora_terminos_muy_cortos(): void
    {
        $this->assertTrue($this->registrar()->search($this->companyId, 'ab')->isEmpty());
    }

    /**
     * Los datos de la entrega tienen que quedar en la factura: es lo que el
     * cliente y el repartidor necesitan ver en el documento.
     */
    public function test_la_factura_lleva_los_datos_de_la_entrega(): void
    {
        $orden = new Order([
            'is_delivery' => true,
            'delivery_metadata' => [
                'customer_name' => 'JUAN PEREZ',
                'customer_phone' => '3001234567',
                'address' => 'CALLE 10 #25-15',
                'address_notes' => 'Torre 2, apto 405',
            ],
        ]);

        $resumen = app(RestaurantOrderEngine::class)->resumenDelDomicilio($orden);

        $this->assertStringContainsString('JUAN PEREZ', $resumen);
        $this->assertStringContainsString('3001234567', $resumen);
        $this->assertStringContainsString('CALLE 10 #25-15', $resumen);
        $this->assertStringContainsString('Torre 2, apto 405', $resumen);
    }

    /** Una orden de mesa no lleva nada de esto. */
    public function test_una_orden_que_no_es_domicilio_no_agrega_nada(): void
    {
        $orden = new Order(['is_delivery' => false]);

        $this->assertSame('', app(RestaurantOrderEngine::class)->resumenDelDomicilio($orden));
    }

    private function registrar(): DeliveryCustomerRegistrar
    {
        return app(DeliveryCustomerRegistrar::class);
    }

    private function recogerBasura(?ThirdParty $cliente): void
    {
        if ($cliente) {
            $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()->whereKey($cliente->id)->forceDelete();
        }
    }
}
