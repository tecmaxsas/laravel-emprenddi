<?php

namespace App\Services\ThirdParties;

use App\Models\ThirdParty;
use Illuminate\Support\Facades\DB;

/**
 * El motor de importación de terceros con dos arreglos que no se pudieron
 * hacer en ThirdPartyImportEngine porque ese archivo es de root.
 *
 * 1. Cada fila se graba dentro de su propio savepoint. En Postgres, cuando una
 *    sentencia falla dentro de una transacción, la transacción entera queda
 *    abortada: todas las filas siguientes fallan con «current transaction is
 *    aborted» y al final el COMMIT se convierte en ROLLBACK. Así, una sola fila
 *    mala tumbaba las demás y no quedaba grabado nada. Con el savepoint solo
 *    se pierde la fila que falló.
 *
 * 2. regime_type vacío entra como 'comun', que es el valor por defecto de la
 *    columna y del formulario. La plantilla lo marca como opcional, pero el
 *    motor mandaba null a una columna NOT NULL y todas las filas fallaban.
 *    (Pasó el 2026-11-03 con 1.171 clientes de PERFUMERIA L&F IMPERIO.)
 *
 * Si algún día el motor base se puede editar, estos arreglos van allá y esta
 * clase sobra.
 */
class ThirdPartyImportEngineConAislamiento extends ThirdPartyImportEngine
{
    /** @var array<string,int>|null longitud máxima de cada columna de texto */
    protected ?array $longitudes = null;

    protected function createOrUpdate(array $data, int $companyId): bool
    {
        return DB::transaction(function () use ($data, $companyId) {
            // Un tercero borrado (soft delete) sigue ocupando el índice único
            // (company_id, document_type, document_number), pero el motor base
            // no lo ve y el INSERT choca. Si lo vuelven a importar es porque lo
            // quieren de vuelta: se restaura y se actualiza.
            $borrado = ThirdParty::onlyTrashed()
                ->where('company_id', $companyId)
                ->where('document_number', trim((string) $data['document_number']))
                ->first();

            if ($borrado) {
                $borrado->restore();
            }

            return parent::createOrUpdate($data, $companyId);
        });
    }

    /**
     * Un texto más largo que la columna pasaba el preview y reventaba al grabar
     * («value too long»). Aquí se avisa antes, con la fila y el campo.
     */
    protected function validateRow(array $data, int $rowNumber, int $companyId): array
    {
        $fila = parent::validateRow($data, $rowNumber, $companyId);

        foreach ($this->longitudesDeColumnas() as $columna => $maximo) {
            $valor = $fila['data'][$columna] ?? null;
            if (is_string($valor) && mb_strlen($valor) > $maximo) {
                $fila['errors'][] = "{$columna} tiene ".mb_strlen($valor)." caracteres y el máximo es {$maximo} (recibido: «{$valor}»)";
            }
        }

        return $fila;
    }

    /** @return array<string,int> */
    protected function longitudesDeColumnas(): array
    {
        return $this->longitudes ??= collect(DB::select(
            'select column_name, character_maximum_length from information_schema.columns
              where table_schema = current_schema() and table_name = ? and character_maximum_length is not null',
            ['third_parties'],
        ))->mapWithKeys(fn ($c) => [$c->column_name => (int) $c->character_maximum_length])->all();
    }

    protected function buildPayload(array $data, int $companyId): array
    {
        $payload = parent::buildPayload($data, $companyId);
        $payload['regime_type'] ??= 'comun';

        return $payload;
    }
}
