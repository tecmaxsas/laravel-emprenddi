<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\Dian\PayrollTestDocument;
use RuntimeException;

/**
 * Corre el set de pruebas de la habilitacion de nomina electronica.
 *
 * La DIAN pide 10 nominas y 10 notas de ajuste, de las que tienen que
 * aceptarse 4 y 4. Mandarlas de a una a mano son 20 clics y llevar la cuenta
 * en un papel, asi que esto las manda en tanda y deja constancia de cada una.
 *
 * Es RETOMABLE, no reinicia: cada pasada envia solo lo que falta. Eso importa
 * porque la DIAN valida de forma asincrona y una nota de ajuste no se puede
 * mandar hasta que su nomina le conste recibida — si sale muy pronto responde
 * NIAE191a. Volver a lanzarlo mas tarde reintenta lo que fallo y respeta lo
 * que ya paso.
 *
 * Va por tandas cortas a proposito: 20 llamadas seguidas a la DIAN dentro de
 * una peticion web se pasan del tiempo limite y el usuario se queda sin saber
 * que alcanzo a salir.
 */
class PayrollTestSetRunner
{
    public const NOMINAS_REQUERIDAS = 10;

    public const NOTAS_REQUERIDAS = 10;

    /** Cuantos documentos se mandan por cada pasada. */
    public const DOCUMENTOS_POR_TANDA = 5;

    public function __construct(
        protected PayrollTestSetBuilder $builder,
    ) {}

    /**
     * @return array{enviados:int, errores:int, pendientes:int, detalle:list<string>, completo:bool}
     */
    public function run(Company $empresa, CompanyConfig $config): array
    {
        $this->exigirRequisitos($empresa, $config);

        $cliente = new DianApiClient($config);
        $this->asegurarAmbiente($config, $cliente);

        $this->sanearRegistrosMalLeidos($empresa);

        $enviados = 0;
        $errores = 0;
        $detalle = [];

        foreach ($this->pendientes($empresa) as $tarea) {
            if ($enviados + $errores >= self::DOCUMENTOS_POR_TANDA) {
                break;
            }

            $resultado = $tarea['kind'] === PayrollTestDocument::KIND_NOMINA
                ? $this->enviarNomina($empresa, $config, $cliente, $tarea['slot'])
                : $this->enviarNota($empresa, $config, $cliente, $tarea['slot']);

            if ($resultado === null) {
                continue; // Su nomina aun no tiene CUNE: se intenta en la proxima.
            }

            $resultado['ok'] ? $enviados++ : $errores++;
            $detalle[] = $resultado['mensaje'];
        }

        return [
            'enviados' => $enviados,
            'errores' => $errores,
            'pendientes' => count($this->pendientes($empresa)),
            'detalle' => $detalle,
            'completo' => $this->completo($empresa),
        ];
    }

    /**
     * Lo que falta por enviar, en el orden en que hay que enviarlo: primero
     * las nominas, porque cada nota necesita el CUNE de la suya.
     *
     * @return list<array{kind:string, slot:int}>
     */
    public function pendientes(Company $empresa): array
    {
        $hechos = PayrollTestDocument::query()
            ->where('company_id', $empresa->id)
            ->where('status', PayrollTestDocument::ENVIADO)
            ->get()
            ->groupBy('kind');

        $nominasHechas = ($hechos[PayrollTestDocument::KIND_NOMINA] ?? collect())->pluck('slot')->all();
        $notasHechas = ($hechos[PayrollTestDocument::KIND_NOTA] ?? collect())->pluck('slot')->all();

        $tareas = [];

        foreach (range(1, self::NOMINAS_REQUERIDAS) as $slot) {
            if (! in_array($slot, $nominasHechas, true)) {
                $tareas[] = ['kind' => PayrollTestDocument::KIND_NOMINA, 'slot' => $slot];
            }
        }

        foreach (range(1, self::NOTAS_REQUERIDAS) as $slot) {
            if (! in_array($slot, $notasHechas, true)) {
                $tareas[] = ['kind' => PayrollTestDocument::KIND_NOTA, 'slot' => $slot];
            }
        }

        return $tareas;
    }

    public function completo(Company $empresa): bool
    {
        return $this->pendientes($empresa) === [];
    }

    /** @return array{nominas:int, notas:int, errores:int} */
    public function resumen(Company $empresa): array
    {
        $documentos = PayrollTestDocument::query()->where('company_id', $empresa->id)->get();

        return [
            'nominas' => $documentos
                ->where('kind', PayrollTestDocument::KIND_NOMINA)
                ->where('status', PayrollTestDocument::ENVIADO)->count(),
            'notas' => $documentos
                ->where('kind', PayrollTestDocument::KIND_NOTA)
                ->where('status', PayrollTestDocument::ENVIADO)->count(),
            'errores' => $documentos->where('status', PayrollTestDocument::ERROR)->count(),
        ];
    }

    /**
     * Corrige los documentos que quedaron marcados con error por culpa de una
     * lectura equivocada de la respuesta, no de un rechazo de la DIAN.
     *
     * Vuelve a mirar la respuesta que ya quedo guardada: si no traia ninguna
     * regla incumplida y si trae CUNE, el documento habia llegado bien. Sin
     * esto habria que reenviar a la DIAN documentos que ya acepto, y ademas
     * sus notas de ajuste nunca saldrian, porque una nota necesita que su
     * nomina figure recibida.
     */
    protected function sanearRegistrosMalLeidos(Company $empresa): void
    {
        $sospechosos = PayrollTestDocument::query()
            ->where('company_id', $empresa->id)
            ->where('status', PayrollTestDocument::ERROR)
            ->whereNotNull('response')
            ->whereNotNull('cune')
            ->get();

        foreach ($sospechosos as $documento) {
            $cuerpo = $documento->response['ResponseDian']['Envelope']['Body'] ?? [];

            $async = $cuerpo['SendTestSetAsyncResponse']['SendTestSetAsyncResult'] ?? null;
            $sync = $this->bloqueSincrono($cuerpo);

            if ($async === null && $sync === null) {
                continue;
            }

            if ($this->erroresDe($async) !== [] || $this->erroresDe($sync) !== []) {
                continue; // Rechazo de verdad: se queda como esta.
            }

            $documento->update([
                'status' => PayrollTestDocument::ENVIADO,
                'error_message' => null,
            ]);
        }
    }

    /** @return array{ok:bool, mensaje:string} */
    protected function enviarNomina(Company $empresa, CompanyConfig $config, DianApiClient $cliente, int $slot): array
    {
        $documento = $this->registro($empresa, PayrollTestDocument::KIND_NOMINA, $slot);

        // El consecutivo NO se reusa entre intentos: la DIAN no admite dos
        // documentos con el mismo numero, ni aunque el primero fuera rechazado.
        $consecutivo = $this->siguienteConsecutivo($config);
        $prefijo = (string) $empresa->payroll_prefix;

        $payload = $this->builder->nomina($empresa, $config, $prefijo, $consecutivo, $slot);
        $respuesta = $cliente->sendPayroll($payload, $config->payroll_test_set_id);

        return $this->registrar($documento, $payload, $respuesta, $prefijo, $consecutivo, 'Nómina '.$slot);
    }

    /** @return array{ok:bool, mensaje:string}|null null si su nomina aun no sirve como predecesora. */
    protected function enviarNota(Company $empresa, CompanyConfig $config, DianApiClient $cliente, int $slot): ?array
    {
        $nomina = PayrollTestDocument::query()
            ->where('company_id', $empresa->id)
            ->where('kind', PayrollTestDocument::KIND_NOMINA)
            ->where('slot', $slot)
            ->first();

        // Sin CUNE de la predecesora la DIAN responde NIAE191a. No se gasta el
        // intento: queda para la proxima pasada.
        if (! $nomina || ! $nomina->puedeSerReemplazada()) {
            return null;
        }

        $documento = $this->registro($empresa, PayrollTestDocument::KIND_NOTA, $slot);

        $consecutivo = $this->siguienteConsecutivo($config);
        $prefijo = (string) ($empresa->payroll_note_prefix ?: $empresa->payroll_prefix);

        $payload = $this->builder->nota($empresa, $config, $nomina, $prefijo, $consecutivo);
        $respuesta = $cliente->sendPayrollReplacementNote($payload, $config->payroll_test_set_id);

        return $this->registrar($documento, $payload, $respuesta, $prefijo, $consecutivo, 'Nota '.$slot);
    }

    /**
     * Guarda el resultado del envio y traduce la respuesta a algo legible.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $respuesta
     * @return array{ok:bool, mensaje:string}
     */
    protected function registrar(
        PayrollTestDocument $documento,
        array $payload,
        array $respuesta,
        string $prefijo,
        int $consecutivo,
        string $etiqueta,
    ): array {
        $data = $respuesta['data'] ?? [];
        $cuerpo = $data['ResponseDian']['Envelope']['Body'] ?? [];

        $async = $cuerpo['SendTestSetAsyncResponse']['SendTestSetAsyncResult'] ?? null;
        $sync = $this->bloqueSincrono($cuerpo);

        $cune = $data['cune'] ?? $data['uuid'] ?? null;
        $errores = $this->erroresDe($async) ?: $this->erroresDe($sync);

        $recibido = $respuesta['ok']
            && $errores === []
            && ($async !== null || filter_var($sync['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN));

        $documento->fill([
            'prefix' => $prefijo,
            'consecutive' => $consecutivo,
            'cune' => $cune,
            'zip_key' => $async['ZipKey'] ?? null,
            'issue_date' => $payload['period']['issue_date'] ?? now()->toDateString(),
            'payload' => $payload,
            'response' => $data ?: null,
        ]);

        if ($recibido) {
            $documento->fill([
                'status' => PayrollTestDocument::ENVIADO,
                'error_message' => null,
            ])->save();

            return ['ok' => true, 'mensaje' => $etiqueta.' → recibida'];
        }

        $motivo = $errores !== []
            ? implode(' · ', array_slice($errores, 0, 3))
            : ($this->mensajeDeFallo($respuesta) ?: 'Sin detalle');

        $documento->fill([
            'status' => PayrollTestDocument::ERROR,
            'error_message' => $motivo,
        ])->save();

        return ['ok' => false, 'mensaje' => $etiqueta.' → '.$motivo];
    }

    /**
     * El veredicto sincrono de la DIAN, venga en el bloque que venga.
     *
     * La nomina responde SendNominaSyncResponse y la nota de ajuste otro
     * nombre distinto. En vez de listarlos todos y quedarnos cortos al
     * siguiente tipo de documento, se busca cualquier *Response/*Result que
     * traiga el veredicto.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>|null
     */
    protected function bloqueSincrono(array $cuerpo): ?array
    {
        foreach ($cuerpo as $clave => $contenido) {
            if (! is_array($contenido) || ! str_ends_with((string) $clave, 'Response')) {
                continue;
            }

            foreach ($contenido as $subclave => $resultado) {
                if (! is_array($resultado) || ! str_ends_with((string) $subclave, 'Result')) {
                    continue;
                }

                if (array_key_exists('IsValid', $resultado) || array_key_exists('StatusCode', $resultado)) {
                    return $resultado;
                }
            }
        }

        return null;
    }

    protected function registro(Company $empresa, string $kind, int $slot): PayrollTestDocument
    {
        return PayrollTestDocument::query()->firstOrNew([
            'company_id' => $empresa->id,
            'kind' => $kind,
            'slot' => $slot,
        ]);
    }

    /**
     * El consecutivo de pruebas avanza con cada envio, salga bien o mal: la
     * DIAN no admite dos documentos con el mismo numero.
     */
    protected function siguienteConsecutivo(CompanyConfig $config): int
    {
        $consecutivo = (int) ($config->payroll_test_consecutive ?: 1);
        $config->update(['payroll_test_consecutive' => $consecutivo + 1]);

        return $consecutivo;
    }

    /**
     * El set de pruebas exige ambiente de habilitacion: en produccion la DIAN
     * rechaza todo con la regla NIE023 y el motivo no se ve en el payload,
     * porque apidian lo toma de la configuracion de la empresa.
     */
    protected function asegurarAmbiente(CompanyConfig $config, DianApiClient $cliente): void
    {
        if ((int) $config->payroll_environment === CompanyConfig::ENV_TEST) {
            return;
        }

        $resultado = $cliente->changeEnvironment(
            (int) ($config->environment ?: CompanyConfig::ENV_TEST),
            CompanyConfig::ENV_TEST,
        );

        if (! $resultado['ok']) {
            throw new RuntimeException(
                'No se pudo poner la nómina en ambiente de habilitación. Sin eso la DIAN rechaza todo el set (regla NIE023).'
            );
        }

        $config->update(['payroll_environment' => CompanyConfig::ENV_TEST]);
    }

    protected function exigirRequisitos(Company $empresa, CompanyConfig $config): void
    {
        if (! $config->api_token) {
            throw new RuntimeException('Falta el token DIAN de la empresa.');
        }

        if (! $config->payroll_software_configured) {
            throw new RuntimeException(
                'Falta registrar el software de nómina (paso 1). La DIAN rechaza los documentos si no está registrado.'
            );
        }

        if (! $config->payroll_test_set_id) {
            throw new RuntimeException(
                'Falta el TestSetId de nómina (paso 3). Lo entrega el portal de la DIAN.'
            );
        }

        if (! $config->dian_municipality_id) {
            throw new RuntimeException(
                'Falta el municipio de la empresa. Sin él apidian responde Server Error.'
            );
        }

        if (! $empresa->payroll_prefix) {
            throw new RuntimeException(
                'Falta el prefijo de nómina. Registra los rangos en el paso 2.'
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $bloque
     * @return list<string>
     */
    protected function erroresDe(?array $bloque): array
    {
        return DianErrorReader::reglas($bloque);
    }

    /** @param  array<string, mixed>  $respuesta */
    protected function mensajeDeFallo(array $respuesta): ?string
    {
        $data = $respuesta['data'] ?? [];

        if (! empty($data['errors'])) {
            $mensajes = [];
            array_walk_recursive($data['errors'], function ($v) use (&$mensajes) {
                if (is_string($v)) {
                    $mensajes[] = $v;
                }
            });

            if ($mensajes !== []) {
                return implode(' · ', array_slice($mensajes, 0, 3));
            }
        }

        foreach (['exception', 'message'] as $clave) {
            if (! empty($data[$clave]) && is_string($data[$clave])) {
                return $data[$clave];
            }
        }

        return $respuesta['error'] ?? null;
    }
}
