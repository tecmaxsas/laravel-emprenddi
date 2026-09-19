<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\ClockFormat;
use App\Support\CurrentCompany;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Cada empresa elige cómo se leen las horas.
 *
 * En Colombia la hora se lee casi siempre en 12 horas: un cajero que ve
 * «6:15 pm» no se equivoca, con «18:15» a veces sí. Por eso el sistema arrancó
 * forzándolo en todas partes.
 *
 * Pero un negocio que trabaja por turnos —transporte, una clínica— prefiere 24
 * horas, donde no hay forma de confundir la mañana con la tarde. Ahora cada
 * empresa elige, y la elección aplica en todo el sistema.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ClockFormatTest extends TestCase
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
        ClockFormat::olvidarCache();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        ClockFormat::olvidarCache();

        parent::tearDown();
    }

    /** Por defecto, 12 horas: es como se lee en Colombia. */
    public function test_por_defecto_son_12_horas(): void
    {
        $this->configurar(null);

        $momento = Carbon::parse('2026-09-15 18:15:42');

        $this->assertSame('6:15 pm', $momento->format(ClockFormat::time()));
        $this->assertSame('15/09/2026 06:15 pm', $momento->format(ClockFormat::datetime()));
    }

    /** Con 24 horas, la misma hora se lee distinto. */
    public function test_en_24_horas_no_lleva_am_ni_pm(): void
    {
        $this->configurar('24');

        $momento = Carbon::parse('2026-09-15 18:15:42');

        $this->assertSame('18:15', $momento->format(ClockFormat::time()));
        $this->assertSame('15/09/2026 18:15', $momento->format(ClockFormat::datetime()));
        $this->assertSame('15/09/2026 18:15:42', $momento->format(ClockFormat::datetimeSeconds()));
    }

    /**
     * La hora de la mañana es donde se nota.
     *
     * A las 6 de la mañana los dos formatos dicen «6:15», y solo el sufijo
     * distingue. A las 6 de la tarde, en 24 horas, no hay ambigüedad posible.
     */
    public function test_la_manana_y_la_tarde_no_se_confunden(): void
    {
        $this->configurar('24');

        $this->assertSame('06:15', Carbon::parse('2026-09-15 06:15')->format(ClockFormat::time()));
        $this->assertSame('18:15', Carbon::parse('2026-09-15 18:15')->format(ClockFormat::time()));
    }

    /** Un valor guardado que ya no existe no imprime basura. */
    public function test_un_formato_desconocido_cae_en_el_de_por_defecto(): void
    {
        $this->configurar('reloj-de-sol');

        $this->assertSame(ClockFormat::POR_DEFECTO, ClockFormat::preferido(),
            'Una cadena de formato inválida saldría impresa tal cual en el tiquete.');
    }

    /** Cada empresa tiene el suyo. */
    public function test_el_formato_es_de_cada_empresa(): void
    {
        $this->configurar('24');

        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base de desarrollo.');
        }

        $this->assertSame('24', ClockFormat::preferido($this->company->fresh()));
        $this->assertNotSame('24', ClockFormat::preferido($otra),
            'Que una empresa prefiera hora militar no se la impone a las demás.');
    }

    /**
     * Ninguna pantalla se guarda el formato por su cuenta.
     *
     * Si una vista escribe «d/m/Y h:i a» a mano, cambiar la preferencia deja
     * media aplicación en un formato y media en el otro — que es peor que no
     * poder elegir.
     */
    public function test_ninguna_pantalla_escribe_el_formato_a_mano(): void
    {
        $salida = shell_exec(
            'grep -rn "h:i a\|g:i a\|H:i:s" '
            .base_path('app').' '.base_path('resources/views')
            .' --include=*.php --include=*.blade.php 2>/dev/null'
            .' | grep -v "ClockFormat.php" || true'
        );

        $this->assertSame('', trim((string) $salida),
            "El formato de hora se escribió a mano en:\n".$salida);
    }

    // --------------------------------------------------------- auxiliares

    private function configurar(?string $formato): void
    {
        $original = $this->company->settings;

        $settings = $original ?? [];
        $settings['clock'] = ['format' => $formato];

        $this->company->update(['settings' => $settings]);
        ClockFormat::olvidarCache();

        $this->limpiar[] = function () use ($original) {
            $this->company->update(['settings' => $original]);
            ClockFormat::olvidarCache();
        };
    }
}
