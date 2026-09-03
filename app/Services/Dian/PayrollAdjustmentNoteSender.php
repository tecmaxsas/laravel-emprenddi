<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\PayrollAdjustmentNote;
use App\Models\PayrollSlip;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emite una nota de ajuste sobre una nomina ya reportada.
 *
 * Es la unica forma de corregir: la DIAN no acepta dos veces el mismo
 * documento, asi que reenviar la nomina no sirve. La nota apunta a la anterior
 * por su CUNE y la reemplaza o la anula.
 *
 * Mismo criterio que PayrollDianSender: la numeracion se reserva al enviar,
 * el intento se marca antes de llamar, y se distingue "la DIAN rechazo" de
 * "nunca llegamos a la DIAN".
 */
class PayrollAdjustmentNoteSender
{
    public function __construct(
        protected PayrollDocumentBuilder $builder,
    ) {}

    /**
     * @param  int  $tipo  1 reemplaza, 2 anula.
     * @return array{ok:bool, message:string, cune:?string, note:PayrollAdjustmentNote}
     */
    public function emit(PayrollSlip $slip, int $tipo = PayrollCatalog::TYPE_NOTE_REEMPLAZAR): array
    {
        $this->exigirQueSePuedaAjustar($slip, $tipo);

        $config = CompanyConfig::query()->where('company_id', $slip->company_id)->first();

        if (! $config || ! $config->api_token) {
            throw new RuntimeException('La empresa no tiene configurada la facturación electrónica DIAN.');
        }

        $nota = $this->reservarNota($slip, $tipo);

        $payload = $this->builder->buildAdjustmentNote($nota->fresh());

        $nota->update([
            'payload' => $payload,
            'dian_status' => PayrollAdjustmentNote::DIAN_SENT,
            'dian_sent_at' => now(),
        ]);

        $cliente = new DianApiClient($config);

        $resultado = $tipo === PayrollCatalog::TYPE_NOTE_ELIMINAR
            ? $cliente->sendPayrollDeletionNote($payload, $config->payroll_test_set_id)
            : $cliente->sendPayrollReplacementNote($payload, $config->payroll_test_set_id);

        return $this->procesarRespuesta($nota, $slip, $resultado);
    }

    /**
     * Una nota corrige un documento que la DIAN ya tiene. Sin CUNE no hay a
     * que apuntar, y la DIAN responde NIAE191a.
     */
    protected function exigirQueSePuedaAjustar(PayrollSlip $slip, int $tipo): void
    {
        if (! in_array($tipo, [PayrollCatalog::TYPE_NOTE_REEMPLAZAR, PayrollCatalog::TYPE_NOTE_ELIMINAR], true)) {
            throw new RuntimeException('Tipo de nota de ajuste desconocido.');
        }

        if (! $slip->cune) {
            throw new RuntimeException(
                'Esta nómina todavía no tiene CUNE: la DIAN no la ha aceptado, así que no hay nada que ajustar. '
                .'Si quieres corregirla, vuelve a liquidar el período y envíala.'
            );
        }

        $anulada = PayrollAdjustmentNote::query()
            ->where('payroll_slip_id', $slip->id)
            ->where('type_note', PayrollCatalog::TYPE_NOTE_ELIMINAR)
            ->where('dian_status', PayrollAdjustmentNote::DIAN_ACCEPTED)
            ->exists();

        if ($anulada) {
            throw new RuntimeException('Esta nómina ya fue anulada ante la DIAN con una nota de eliminación.');
        }
    }

    /**
     * Reserva numeracion propia para la nota y copia a que documento corrige.
     *
     * El predecesor se copia y no se lee de la colilla al vuelo: si mas
     * adelante se emite otra nota, esta tiene que seguir apuntando a lo que
     * corrigio en su momento.
     */
    protected function reservarNota(PayrollSlip $slip, int $tipo): PayrollAdjustmentNote
    {
        return DB::transaction(function () use ($slip, $tipo) {
            $empresa = Company::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($slip->company_id);

            $prefijo = $empresa->payroll_note_prefix;

            if (! $prefijo) {
                throw new RuntimeException(
                    'La empresa no tiene prefijo de notas de ajuste. Regístralo en Facturación '
                    .'Electrónica DIAN → Nómina electrónica → paso 2.'
                );
            }

            $consecutivo = (int) ($empresa->payroll_note_next_consecutive ?: 1);
            $empresa->update(['payroll_note_next_consecutive' => $consecutivo + 1]);

            return PayrollAdjustmentNote::create([
                'company_id' => $slip->company_id,
                'payroll_slip_id' => $slip->id,
                'type_note' => $tipo,
                'prefix' => $prefijo,
                'consecutive' => $consecutivo,
                'predecessor_prefix' => $slip->prefix,
                'predecessor_consecutive' => $slip->consecutive,
                'predecessor_cune' => $slip->cune,
                'predecessor_issue_date' => $slip->dian_sent_at?->toDateString() ?? now()->toDateString(),
                'dian_status' => PayrollAdjustmentNote::DIAN_PENDING,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @return array{ok:bool, message:string, cune:?string, note:PayrollAdjustmentNote}
     */
    protected function procesarRespuesta(PayrollAdjustmentNote $nota, PayrollSlip $slip, array $resultado): array
    {
        $data = $resultado['data'] ?? [];
        $cuerpo = $data['ResponseDian']['Envelope']['Body'] ?? [];
        $cune = $data['cune'] ?? $data['uuid'] ?? null;

        // Al set de pruebas la DIAN contesta por la via asincrona: acusa recibo
        // y valida despues, asi que no hay veredicto todavia.
        $async = $cuerpo['SendTestSetAsyncResponse']['SendTestSetAsyncResult'] ?? null;
        $sync = $cuerpo['SendNominaAjusteSyncResponse']['SendNominaAjusteSyncResult']
            ?? $cuerpo['SendNominaSyncResponse']['SendNominaSyncResult']
            ?? null;

        $errores = DianErrorReader::reglas($async) ?: DianErrorReader::reglas($sync);

        if (! $resultado['ok'] || $errores !== []) {
            $motivo = $errores !== []
                ? implode(' · ', array_slice($errores, 0, 4))
                : ($resultado['error'] ?? 'No fue posible emitir la nota de ajuste.');

            $nota->update([
                'dian_status' => PayrollAdjustmentNote::DIAN_REJECTED,
                'dian_error_message' => $motivo,
                'dian_response' => $data ?: null,
                'cune' => $cune,
            ]);

            return ['ok' => false, 'message' => $motivo, 'cune' => $cune, 'note' => $nota->fresh()];
        }

        $aceptada = filter_var($sync['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $nota->update([
            'dian_status' => $aceptada
                ? PayrollAdjustmentNote::DIAN_ACCEPTED
                : PayrollAdjustmentNote::DIAN_SENT,
            'dian_status_code' => $sync['StatusCode'] ?? null,
            'cune' => $cune,
            'dian_error_message' => null,
            'dian_response' => $data ?: null,
        ]);

        // La colilla vuelve a estar alineada con lo que la DIAN sabe.
        $slip->update(['dian_needs_adjustment' => false]);

        return [
            'ok' => true,
            'message' => $aceptada
                ? 'Nota de ajuste aceptada por la DIAN'
                : 'La DIAN recibió la nota de ajuste. La valida de forma asíncrona: el resultado no viene '
                    .'en esta respuesta.',
            'cune' => $cune,
            'note' => $nota->fresh(),
        ];
    }
}
