<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DefaultAccounts;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resolver una cuenta del PUC por su código.
 *
 * Veintiséis sitios lo hacían así:
 *
 *     Account::where('company_id', $id)->where('code', '1435')->value('id')
 *
 * y le pasaban el resultado a un asiento. En un plan de cuentas donde `1435`
 * es una hoja funciona. Pero `1435` es una cuenta MAYOR —debajo tiene
 * `143505 Inventario general`— y en los planes sembrados completos el asiento
 * se iba contra la mayor.
 *
 * **Eso no es un detalle de forma.** El informe por niveles suma el
 * movimiento en la mayor Y en el total de sus hijas: cuenta dos veces. El
 * sistema ya lo prohibía con `accepts_movements`, pero nadie le preguntaba.
 *
 * Y lo peor era cómo fallaba: solo en los planes donde ese código es mayor.
 * Funciona en desarrollo y falla en la empresa con el PUC completo, meses
 * después, en una revisión contable.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CuentaPorCodigoTest extends TestCase
{
    private Company $empresa;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->empresa);
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /**
     * Si el código es una cuenta mayor, baja a su hija.
     *
     * Es la regla entera. Sin ella, el asiento se va contra la mayor.
     */
    public function test_una_cuenta_mayor_baja_a_su_hija(): void
    {
        [$mayor, $hija] = $this->mayorConHija('9911', '991105');

        $resuelta = DefaultAccounts::porCodigo('9911', $this->empresa->id);

        $this->assertSame($hija->id, $resuelta,
            'Contabilizar contra una cuenta mayor hace que el informe por niveles cuente dos veces.');
        $this->assertNotSame($mayor->id, $resuelta);
    }

    /** Si el código ya acepta movimientos, se usa tal cual. */
    public function test_una_cuenta_hoja_se_usa_tal_cual(): void
    {
        [, $hija] = $this->mayorConHija('9912', '991205');

        $this->assertSame($hija->id, DefaultAccounts::porCodigo('991205', $this->empresa->id));
    }

    /**
     * Entre varias hijas, siempre la misma.
     *
     * Una cuenta que cambia entre ejecuciones parte la contabilidad en dos
     * mitades que no cuadran.
     */
    public function test_entre_varias_hijas_elige_siempre_la_misma(): void
    {
        [, $primera] = $this->mayorConHija('9913', '991305');
        $this->cuenta('991310', 'ZZPUC hija segunda', acepta: true);

        $a = DefaultAccounts::porCodigo('9913', $this->empresa->id);
        $b = DefaultAccounts::porCodigo('9913', $this->empresa->id);

        $this->assertSame($primera->id, $a, 'La de menor código.');
        $this->assertSame($a, $b);
    }

    /** Una hija inactiva no sirve. */
    public function test_una_hija_inactiva_no_cuenta(): void
    {
        $this->cuenta('9914', 'ZZPUC mayor sola', acepta: false);
        $this->cuenta('991405', 'ZZPUC hija apagada', acepta: true, activa: false);

        $this->assertNull(DefaultAccounts::porCodigo('9914', $this->empresa->id));
    }

    /** Sin hija que reciba movimientos, mejor nada que una cuenta inventada. */
    public function test_una_mayor_sin_hijas_no_devuelve_nada(): void
    {
        $this->cuenta('9915', 'ZZPUC mayor sin hijas', acepta: false);

        $this->assertNull(DefaultAccounts::porCodigo('9915', $this->empresa->id));
    }

    /** Un código que no existe no devuelve nada. */
    public function test_un_codigo_inexistente_no_devuelve_nada(): void
    {
        $this->assertNull(DefaultAccounts::porCodigo('999999', $this->empresa->id));
        $this->assertNull(DefaultAccounts::porCodigo('', $this->empresa->id));
    }

    /** No se cruza de empresa. */
    public function test_no_devuelve_la_cuenta_de_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->empresa->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Se necesita una segunda empresa.');
        }

        $this->cuenta('9916', 'ZZPUC solo de esta empresa', acepta: true);

        $this->assertNotNull(DefaultAccounts::porCodigo('9916', $this->empresa->id));
        $this->assertNull(DefaultAccounts::porCodigo('9916', $otra->id));
    }

    /**
     * Ningún motor vuelve a buscar la cuenta a mano.
     *
     * Esta es la que impide la recaída: el problema no fue que un sitio
     * estuviera mal, fue que la regla estaba escrita veintiséis veces y
     * ninguna preguntaba por `accepts_movements`.
     */
    public function test_ningun_servicio_busca_la_cuenta_por_su_cuenta(): void
    {
        $sueltos = [];

        foreach (glob(app_path('Services/*/*.php')) as $archivo) {
            $fuente = file_get_contents($archivo);

            if (preg_match("/->where\('code', '\d+'\)/", $fuente)) {
                $sueltos[] = str_replace(app_path(), 'app', $archivo);
            }
        }

        $this->assertSame([], $sueltos,
            'Estos servicios resuelven la cuenta por código a mano en vez de usar '
            .'DefaultAccounts::porCodigo(), que es la que garantiza que acepte '
            .'movimientos: '.implode(', ', $sueltos));
    }

    // --------------------------------------------------------- auxiliares

    /** @return array{0: Account, 1: Account} */
    private function mayorConHija(string $codigoMayor, string $codigoHija): array
    {
        return [
            $this->cuenta($codigoMayor, 'ZZPUC mayor '.$codigoMayor, acepta: false),
            $this->cuenta($codigoHija, 'ZZPUC hija '.$codigoHija, acepta: true),
        ];
    }

    private function cuenta(string $codigo, string $nombre, bool $acepta, bool $activa = true): Account
    {
        $cuenta = Account::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => $codigo,
            'name' => $nombre,
            'type' => 'activo',
            'nature' => 'debit',
            'level' => strlen($codigo) <= 4 ? 2 : 4,
            'accepts_movements' => $acepta,
            'requires_third_party' => false,
            'active' => $activa,
        ]);

        $this->limpiar[] = fn () => DB::table('accounts')->where('id', $cuenta->id)->delete();

        return $cuenta;
    }
}
