<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El aviso de respaldos.
 *
 * Existe por una razón concreta: **un respaldo que falla en silencio es peor
 * que no tener respaldo**, porque da la tranquilidad sin dar la protección. El
 * cron corre de madrugada y nadie mira su salida; el día que hace falta el
 * archivo, resulta que llevaba meses sin escribirse.
 *
 * Por eso lo que se prueba aquí es sobre todo el **código de salida**: es lo
 * que permite que un monitor externo o el propio cron se den cuenta. Un
 * comando que informa bonito y siempre devuelve cero no sirve de alarma.
 */
class BackupStatusTest extends TestCase
{
    private string $ruta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ruta = storage_path('backups/estado.json');

        if (! is_dir(dirname($this->ruta))) {
            mkdir(dirname($this->ruta), 0755, true);
        }

        $this->borrarEstado();
    }

    protected function tearDown(): void
    {
        $this->borrarEstado();

        parent::tearDown();
    }

    /** Sin respaldos, el comando falla: no puede quedarse callado. */
    public function test_sin_respaldos_avisa_y_falla(): void
    {
        $this->artisan('backup:status')
            ->expectsOutputToContain('No hay ningún respaldo registrado')
            ->assertExitCode(1);
    }

    /** Un respaldo reciente y fuera del servidor: todo en orden. */
    public function test_un_respaldo_reciente_y_externo_pasa(): void
    {
        $this->escribirEstado(['fecha' => now()->subHours(6)->toIso8601String()]);

        $this->artisan('backup:status')
            ->expectsOutputToContain('Los respaldos están al día')
            ->assertExitCode(0);
    }

    /** Un respaldo que falló tiene que gritar, aunque sea de hace un rato. */
    public function test_un_respaldo_fallido_falla(): void
    {
        $this->escribirEstado([
            'resultado' => 'fallo',
            'detalle' => 'el dump solo tiene 0 tablas con datos',
            'fecha' => now()->subHour()->toIso8601String(),
        ]);

        $this->artisan('backup:status')
            ->expectsOutputToContain('0 tablas con datos')
            ->assertExitCode(1);
    }

    /**
     * El caso más traicionero: el último respaldo salió bien, pero fue hace
     * una semana. Sin este control, el cron podría estar muerto y el comando
     * seguiría diciendo «ok».
     */
    public function test_un_respaldo_viejo_falla_aunque_haya_salido_bien(): void
    {
        $this->escribirEstado(['fecha' => now()->subDays(7)->toIso8601String()]);

        $this->artisan('backup:status')
            ->expectsOutputToContain('Han pasado')
            ->assertExitCode(1);

        // Con un límite más laxo, el mismo respaldo pasa: el umbral es del que
        // consulta, no del comando.
        $this->artisan('backup:status', ['--horas' => 24 * 30])
            ->assertExitCode(0);
    }

    /**
     * Un respaldo en el mismo disco no protege de lo que de verdad pasa: que
     * el disco muera o que borren la máquina.
     */
    public function test_un_respaldo_que_no_salio_del_servidor_falla(): void
    {
        $this->escribirEstado([
            'fecha' => now()->subHour()->toIso8601String(),
            'fuera_del_servidor' => false,
        ]);

        $this->artisan('backup:status')
            ->expectsOutputToContain('no salió del servidor')
            ->assertExitCode(1);
    }

    /** Un estado ilegible no puede pasar por «todo bien». */
    public function test_un_estado_corrupto_falla(): void
    {
        file_put_contents($this->ruta, '{esto no es json');

        $this->artisan('backup:status')
            ->expectsOutputToContain('corrupto')
            ->assertExitCode(1);
    }

    /** @param  array<string, mixed>  $cambios */
    private function escribirEstado(array $cambios = []): void
    {
        file_put_contents($this->ruta, json_encode(array_merge([
            'resultado' => 'ok',
            'detalle' => 'respaldo completo',
            'fecha' => now()->toIso8601String(),
            'archivo' => 'emprenddi-de-prueba.tar.gz',
            'tamano_bytes' => 48211234,
            'fuera_del_servidor' => true,
        ], $cambios)));
    }

    private function borrarEstado(): void
    {
        if (is_file($this->ruta)) {
            unlink($this->ruta);
        }
    }
}
