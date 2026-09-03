<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\PayrollSlip;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Envia a la DIAN la nomina electronica de una colilla ya liquidada.
 *
 * Mismo criterio que DianInvoiceSender: se marca el intento ANTES de llamar
 * para que un corte de red quede registrado, y se distingue "la DIAN
 * respondio rechazando" de "nunca llegamos a la DIAN" — solo lo segundo tiene
 * sentido reintentarlo tal cual.
 *
 * La numeracion se reserva aqui y no al liquidar: un consecutivo quemado por
 * una colilla que nunca se envio deja huecos, y la DIAN los pregunta.
 */
class PayrollDianSender
{
    public function __construct(
        protected PayrollDocumentBuilder $builder,
    ) {}

    /**
     * @param  string|null  $testSetId  Fuerza el set de pruebas. Si va null se
     *                                  usa el configurado en la empresa.
     * @return array{ok:bool, message:string, cune:?string, status_code:?string, reached_dian:bool}
     */
    public function send(PayrollSlip $slip, ?string $testSetId = null): array
    {
        if ($slip->dian_status === PayrollSlip::DIAN_ACCEPTED) {
            throw new RuntimeException('Esta colilla ya fue aceptada por la DIAN.');
        }

        $config = CompanyConfig::query()->where('company_id', $slip->company_id)->first();

        if (! $config || ! $config->company_registered) {
            throw new RuntimeException(
                'La empresa no ha completado el registro DIAN. Ve a Configuración → Facturación Electrónica DIAN.'
            );
        }

        if (! $config->api_token) {
            throw new RuntimeException('Falta el token DIAN de la empresa.');
        }

        $this->asignarNumeracion($slip);

        $payload = $this->builder->build($slip->fresh());

        $slip->update([
            'dian_status' => PayrollSlip::DIAN_SENT,
            'dian_sent_at' => now(),
        ]);

        // Con TestSetId configurado el envio va al set de pruebas de la
        // habilitacion; sin el, a produccion. Se puede forzar por parametro
        // para reenviar una prueba puntual.
        $result = (new DianApiClient($config))->sendPayroll(
            $payload,
            $testSetId ?? $config->payroll_test_set_id,
        );

        return $this->procesarRespuesta($slip, $result);
    }

    /**
     * Reserva prefijo y consecutivo de nomina para la colilla.
     *
     * La nomina electronica no lleva resolucion de la DIAN: el prefijo y el
     * rango los define el empleador. Se toma bajo bloqueo para que dos envios
     * simultaneos no se lleven el mismo numero.
     */
    protected function asignarNumeracion(PayrollSlip $slip): void
    {
        if ($slip->prefix && $slip->consecutive) {
            return; // Ya tiene numero de un intento anterior: se reusa.
        }

        DB::transaction(function () use ($slip) {
            $empresa = Company::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($slip->company_id);

            if (! $empresa->payroll_prefix) {
                throw new RuntimeException(
                    'La empresa no tiene prefijo de nómina electrónica. Configúralo en Facturación Electrónica DIAN.'
                );
            }

            $consecutivo = (int) ($empresa->payroll_next_consecutive ?: 1);

            $slip->update([
                'prefix' => $empresa->payroll_prefix,
                'consecutive' => $consecutivo,
            ]);

            $empresa->update(['payroll_next_consecutive' => $consecutivo + 1]);
        });
    }

    /**
     * @return array{ok:bool, message:string, cune:?string, status_code:?string, reached_dian:bool}
     */
    protected function procesarRespuesta(PayrollSlip $slip, array $result): array
    {
        $data = $result['data'] ?? [];

        if (! $result['ok'] && empty($data)) {
            return $this->rechazar($slip, $result['error'] ?? 'Error de conexión con apidian', $result, alcanzoDian: false);
        }

        if (isset($data['errors'])) {
            return $this->rechazar($slip, 'Errores de validación: '.$this->aplanarErrores($data['errors']), $data, alcanzoDian: false);
        }

        if (isset($data['exception'])) {
            return $this->rechazar($slip, 'Excepción del API: '.$data['exception'], $data, alcanzoDian: false);
        }

        // La nomina responde SendNominaSyncResponse, no SendBillSyncResponse.
        $respuestaDian = $data['ResponseDian']['Envelope']['Body']['SendNominaSyncResponse']['SendNominaSyncResult']
            ?? $data['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult']
            ?? null;

        $cune = $data['cune'] ?? $data['uuid'] ?? null;

        if (! $respuestaDian) {
            return $this->rechazar($slip, 'Sin respuesta de la DIAN. Reintenta en unos minutos.', $data, alcanzoDian: false, cune: $cune);
        }

        $statusCode = (string) ($respuestaDian['StatusCode'] ?? '');
        $esValido = filter_var($respuestaDian['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($esValido && $cune) {
            $slip->update([
                'dian_status' => PayrollSlip::DIAN_ACCEPTED,
                'dian_status_code' => $statusCode,
                'cune' => $cune,
                'qr_url' => 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cune,
                'dian_error_message' => null,
                'dian_response' => $data,
            ]);

            return [
                'ok' => true,
                'message' => 'Nómina aceptada por la DIAN',
                'cune' => $cune,
                'status_code' => $statusCode,
                'reached_dian' => true,
            ];
        }

        $mensaje = $this->extraerError($respuestaDian, $statusCode);

        return $this->rechazar($slip, $mensaje, $data, alcanzoDian: true, cune: $cune, statusCode: $statusCode);
    }

    /**
     * @return array{ok:bool, message:string, cune:?string, status_code:?string, reached_dian:bool}
     */
    protected function rechazar(
        PayrollSlip $slip,
        string $mensaje,
        array $respuesta,
        bool $alcanzoDian,
        ?string $cune = null,
        ?string $statusCode = null,
    ): array {
        $slip->update([
            'dian_status' => PayrollSlip::DIAN_REJECTED,
            'dian_status_code' => $statusCode,
            'cune' => $cune,
            'dian_error_message' => $mensaje,
            'dian_response' => $respuesta,
        ]);

        return [
            'ok' => false,
            'message' => $mensaje,
            'cune' => $cune,
            'status_code' => $statusCode,
            'reached_dian' => $alcanzoDian,
        ];
    }

    protected function aplanarErrores(array $errores): string
    {
        $mensajes = [];

        array_walk_recursive($errores, function ($valor) use (&$mensajes) {
            if (is_string($valor)) {
                $mensajes[] = $valor;
            }
        });

        return implode(' · ', array_slice($mensajes, 0, 6));
    }

    protected function extraerError(array $respuestaDian, string $statusCode): string
    {
        $descripcion = (string) ($respuestaDian['StatusDescription'] ?? '');
        $mensaje = (string) ($respuestaDian['StatusMessage'] ?? '');

        // Las reglas concretas que fallaron van aqui, no en la descripcion.
        // Segun el caso apidian lo entrega como string, como lista bajo
        // `string`, o como lista directa.
        $detalle = $respuestaDian['ErrorMessage']['string'] ?? $respuestaDian['ErrorMessage'] ?? null;
        if (is_string($detalle)) {
            $detalle = [$detalle];
        }

        $reglas = [];
        if (is_array($detalle)) {
            array_walk_recursive($detalle, function ($v) use (&$reglas) {
                if (is_string($v) && trim($v) !== '') {
                    $reglas[] = trim($v);
                }
            });
        }

        $partes = array_filter([
            $statusCode !== '' ? "[{$statusCode}]" : null,
            $descripcion ?: $mensaje,
            $reglas !== [] ? implode(' · ', array_slice($reglas, 0, 4)) : null,
        ]);

        return implode(' ', $partes) ?: 'La DIAN rechazó el documento sin detalle.';
    }
}
