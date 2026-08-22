<?php

namespace App\Services\Parking;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSpace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creacion masiva de espacios de parqueo a partir de un rango.
 *
 * El codigo final se arma como: prefijo + secuencia + sufijo
 *
 *   numerico:   prefijo "A-", 1..30, relleno 2  ->  A-01 ... A-30
 *   alfabetico: prefijo "P1-", A..H              ->  P1-A ... P1-H
 *
 * Es idempotente frente a lo que ya existe: los codigos ya usados en ese
 * parqueadero se omiten (no se pisan), y los que estaban en papelera se
 * restauran con los datos del lote en vez de reventar contra el indice
 * unico (parking_lot_id, code), que si cuenta los borrados logicos.
 */
class ParkingSpaceBulkCreator
{
    public const MODE_NUMERIC = 'numeric';

    public const MODE_ALPHA = 'alpha';

    public const MODES = [
        self::MODE_NUMERIC => 'Numérico (1, 2, 3...)',
        self::MODE_ALPHA => 'Alfabético (A, B, C...)',
    ];

    /** Tope duro por lote, para no colgar la request ni llenar la tabla por un typo. */
    public const MAX_PER_BATCH = 500;

    /** Largo de la columna code en parking_spaces. */
    public const MAX_CODE_LENGTH = 30;

    /**
     * Genera la lista de codigos del rango, en orden.
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException si el rango es invalido o excede el tope
     */
    public static function buildCodes(array $data): array
    {
        $mode = $data['mode'] ?? self::MODE_NUMERIC;
        $prefix = trim((string) ($data['prefix'] ?? ''));
        $suffix = trim((string) ($data['suffix'] ?? ''));
        $step = max(1, (int) ($data['step'] ?? 1));

        $sequence = $mode === self::MODE_ALPHA
            ? self::alphaSequence((string) ($data['from'] ?? ''), (string) ($data['to'] ?? ''), $step)
            : self::numericSequence($data, $step);

        $codes = [];
        foreach ($sequence as $token) {
            $code = $prefix.$token.$suffix;
            if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
                throw new InvalidArgumentException(
                    "El código «{$code}» supera los ".self::MAX_CODE_LENGTH.' caracteres.'
                );
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /** @return array<int, string> */
    protected static function numericSequence(array $data, int $step): array
    {
        $from = (int) ($data['from'] ?? 0);
        $to = (int) ($data['to'] ?? 0);
        $padding = max(0, (int) ($data['padding'] ?? 0));

        if ($to < $from) {
            throw new InvalidArgumentException('El número final debe ser mayor o igual al inicial.');
        }

        $total = (int) floor(($to - $from) / $step) + 1;
        self::assertWithinBatch($total);

        $out = [];
        for ($i = $from; $i <= $to; $i += $step) {
            $out[] = $padding > 0 ? str_pad((string) $i, $padding, '0', STR_PAD_LEFT) : (string) $i;
        }

        return $out;
    }

    /** @return array<int, string> */
    protected static function alphaSequence(string $from, string $to, int $step): array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if (! preg_match('/^[A-Z]{1,3}$/', $from) || ! preg_match('/^[A-Z]{1,3}$/', $to)) {
            throw new InvalidArgumentException('En modo alfabético usa solo letras (A, B... o AA, AB...).');
        }

        // "Z" < "AA" en la secuencia real, así que primero manda el largo.
        if (mb_strlen($to) < mb_strlen($from) || (mb_strlen($to) === mb_strlen($from) && $to < $from)) {
            throw new InvalidArgumentException('La letra final debe ir después de la inicial.');
        }

        $out = [];
        $current = $from;
        $i = 0;
        while (true) {
            if ($i % $step === 0) {
                $out[] = $current;
                self::assertWithinBatch(count($out));
            }
            if ($current === $to) {
                break;
            }
            $current++; // incremento de strings de PHP: Z -> AA
            $i++;
            self::assertWithinBatch($i); // el salto no puede volver infinito el recorrido
        }

        return $out;
    }

    protected static function assertWithinBatch(int $total): void
    {
        if ($total > self::MAX_PER_BATCH) {
            throw new InvalidArgumentException(
                'El rango genera '.$total.' espacios y el máximo por lote es '.self::MAX_PER_BATCH.'.'
            );
        }
    }

    /**
     * Crea los espacios del rango en el parqueadero indicado.
     *
     * @return array{created:int, restored:int, skipped:array<int, string>, codes:array<int, string>}
     */
    public function create(array $data): array
    {
        $codes = self::buildCodes($data);
        $lotId = (int) $data['parking_lot_id'];

        // El parqueadero se resuelve con el scope de empresa puesto: asi el
        // lote solo puede caer en un parqueadero propio, y de paso tomamos
        // de ahi el company_id en vez de depender del usuario en sesion.
        $lot = ParkingLot::query()->find($lotId);
        if (! $lot) {
            throw new InvalidArgumentException('El parqueadero seleccionado no existe o no pertenece a esta empresa.');
        }

        $attributes = [
            'company_id' => $lot->company_id,
            'parking_lot_id' => $lotId,
            'vehicle_type_id' => $data['vehicle_type_id'] ?? null,
            'zone' => $data['zone'] ?? null,
            'status' => $data['status'] ?? ParkingSpace::STATUS_FREE,
            'is_accessibility' => (bool) ($data['is_accessibility'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];

        return DB::transaction(function () use ($codes, $lotId, $attributes) {
            // withTrashed porque el índice único (parking_lot_id, code) también
            // cuenta los borrados lógicos.
            $existing = ParkingSpace::withTrashed()
                ->where('parking_lot_id', $lotId)
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');

            $created = 0;
            $restored = 0;
            $skipped = [];

            foreach ($codes as $code) {
                $found = $existing->get($code);

                if ($found && ! $found->trashed()) {
                    $skipped[] = $code;

                    continue;
                }

                if ($found) {
                    $found->fill($attributes);
                    $found->deleted_at = null;
                    $found->save();
                    $restored++;

                    continue;
                }

                ParkingSpace::create($attributes + ['code' => $code]);
                $created++;
            }

            return [
                'created' => $created,
                'restored' => $restored,
                'skipped' => $skipped,
                'codes' => $codes,
            ];
        });
    }
}
