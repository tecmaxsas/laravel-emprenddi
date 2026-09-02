<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\DianDvCalculator;
use Tests\TestCase;

/**
 * El digito de verificacion "0" es valido y no puede tratarse como vacio.
 *
 * En PHP el string "0" es falsy, asi que comprobarlo con `! $dv` o con
 * `empty($dv)` da "no tiene DV". Eso bloqueaba el registro ante la DIAN de una
 * empresa con DV 0, le quitaba el guion al NIT en los documentos y omitia el
 * dv del payload que se le envia a la DIAN por cada cliente.
 */
class DvCeroTest extends TestCase
{
    private Company $company;

    private ?string $dvOriginal = null;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->dvOriginal = $this->company->dv;
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        $this->company->update(['dv' => $this->dvOriginal]);
        parent::tearDown();
    }

    public function test_el_cero_cuenta_como_digito_de_verificacion(): void
    {
        $this->assertTrue(DianDvCalculator::hasValue('0'), 'El DV 0 es válido.');
        $this->assertTrue(DianDvCalculator::hasValue(0));
        $this->assertTrue(DianDvCalculator::hasValue('7'));
    }

    public function test_lo_que_de_verdad_esta_vacio_sigue_sin_contar(): void
    {
        $this->assertFalse(DianDvCalculator::hasValue(null));
        $this->assertFalse(DianDvCalculator::hasValue(''));
        $this->assertFalse(DianDvCalculator::hasValue('   '));
        // Texto que aparece en datos importados de otros sistemas.
        $this->assertFalse(DianDvCalculator::hasValue('NULL'));
        $this->assertFalse(DianDvCalculator::hasValue('null'));
    }

    public function test_el_nit_de_la_empresa_conserva_el_guion_con_dv_cero(): void
    {
        $this->company->update(['nit' => '1060648527', 'dv' => '0']);

        $this->assertSame('1060648527-0', $this->company->fresh()->fullNit(),
            'Con DV 0 el guion desaparecía y el NIT salía incompleto en los documentos.');
    }

    public function test_el_documento_del_tercero_conserva_el_guion_con_dv_cero(): void
    {
        $tercero = ThirdParty::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'document_number' => 'ZZ-DV-0'],
            [
                'person_type' => 'juridica',
                'document_type' => 'nit',
                'name' => 'ZZ Tercero DV Cero',
                'dv' => '0',
                'is_customer' => true,
                'active' => true,
            ]
        );
        $tercero->update(['dv' => '0']);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($tercero->id)->forceDelete();

        $this->assertSame('ZZ-DV-0-0', $tercero->fresh()->fullDocument());
    }

    /**
     * Es lo que rompia el registro ante la DIAN: la pantalla exigia NIT y DV,
     * y con DV 0 daba por hecho que faltaba.
     */
    public function test_una_empresa_con_dv_cero_pasa_el_control_de_datos_basicos(): void
    {
        $this->company->update(['nit' => '1060648527', 'dv' => '0']);
        $empresa = $this->company->fresh();

        $bloquea = ! $empresa->nit || ! DianDvCalculator::hasValue($empresa->dv);

        $this->assertFalse($bloquea, 'Con NIT y DV 0 no debe pedir "configura los datos básicos".');

        // Y la comprobación vieja sí bloqueaba: por eso hubo que cambiarla.
        $this->assertTrue(! $empresa->nit || ! $empresa->dv,
            'La comprobación anterior trataba el 0 como vacío.');
    }
}
