<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Depura la bitácora antigua.
 *
 * El modelo prohíbe borrar entradas una por una a propósito, así que este es el
 * único camino: por fecha, en bloque, desde la consola —donde queda en el
 * historial del servidor— y nunca por debajo de un mínimo razonable.
 *
 *   php artisan audit:purge                  # deja los últimos 365 días
 *   php artisan audit:purge --days=730
 *   php artisan audit:purge --days=400 --force   # sin preguntar (cron)
 */
class PurgeAuditLog extends Command
{
    protected $signature = 'audit:purge
                            {--days=365 : Cuántos días de bitácora conservar}
                            {--force : No preguntar antes de borrar}';

    protected $description = 'Borra los registros de auditoría más antiguos que el plazo indicado';

    /**
     * Por debajo de tres meses la bitácora deja de servir para lo que sirve:
     * revisar un cierre contable o un reclamo del mes pasado.
     */
    private const MINIMO_DIAS = 90;

    public function handle(): int
    {
        $dias = (int) $this->option('days');

        if ($dias < self::MINIMO_DIAS) {
            $this->error('Se deben conservar al menos '.self::MINIMO_DIAS." días de bitácora (pediste {$dias}).");

            return self::FAILURE;
        }

        $corte = now()->subDays($dias)->startOfDay();
        $tabla = (new AuditLog)->getTable();

        $cuantos = DB::table($tabla)->where('created_at', '<', $corte)->count();

        if ($cuantos === 0) {
            $this->info("No hay registros anteriores al {$corte->format('d/m/Y')}. Nada que borrar.");

            return self::SUCCESS;
        }

        $this->warn("Se van a borrar {$cuantos} registros anteriores al {$corte->format('d/m/Y')}.");

        if (! $this->option('force') && ! $this->confirm('¿Continuar?')) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        // En bloques para no bloquear la tabla en instalaciones grandes.
        $borrados = 0;

        do {
            $lote = DB::table($tabla)
                ->where('created_at', '<', $corte)
                ->limit(1000)
                ->pluck('id');

            if ($lote->isEmpty()) {
                break;
            }

            $borrados += DB::table($tabla)->whereIn('id', $lote)->delete();
        } while (true);

        $this->info("Listo: {$borrados} registros borrados. La bitácora conserva los últimos {$dias} días.");

        return self::SUCCESS;
    }
}
