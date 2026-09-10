<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * En qué quedó el último respaldo.
 *
 * Existe por una razón concreta: **un respaldo que falla en silencio es peor
 * que no tener respaldo**, porque da la tranquilidad sin dar la protección. El
 * cron corre de madrugada, nadie mira su salida, y el día que hace falta el
 * archivo resulta que llevaba tres meses sin escribirse.
 *
 * El script de respaldo deja un `estado.json` pase lo que pase —incluso si se
 * interrumpe a la mitad— y esto lo lee y devuelve un código de salida distinto
 * de cero cuando algo va mal, para que un monitor o el propio cron lo note.
 *
 *   php artisan backup:status
 *   php artisan backup:status --horas=48
 */
class BackupStatus extends Command
{
    protected $signature = 'backup:status
                            {--horas=30 : A partir de cuántas horas sin respaldo se considera un problema}';

    protected $description = 'Informa si el último respaldo se completó y cuándo';

    public function handle(): int
    {
        $ruta = storage_path('backups/estado.json');

        if (! is_file($ruta)) {
            $this->error('No hay ningún respaldo registrado.');
            $this->line('  Nunca se ha corrido scripts/backup.sh, o el cron no está puesto.');
            $this->line('  Instálalo con: sudo bash scripts/backup.sh --instalar-cron');

            return self::FAILURE;
        }

        try {
            $estado = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->error('El archivo de estado del respaldo está corrupto: '.$ruta);

            return self::FAILURE;
        }

        $fecha = Carbon::parse($estado['fecha'] ?? 'now');
        // Carbon 3 devuelve decimales: «129.977 horas» no se lee, se descifra.
        $horas = (int) $fecha->diffInHours(now());
        $limite = (int) $this->option('horas');

        $this->line('Último respaldo:  '.$fecha->format('d/m/Y h:i a').' ('.$fecha->diffForHumans().')');
        $this->line('Archivo:          '.($estado['archivo'] ?: '—'));
        $this->line('Tamaño:           '.$this->tamano((int) ($estado['tamano_bytes'] ?? 0)));
        $this->line('Fuera del servidor: '.(($estado['fuera_del_servidor'] ?? false) ? 'sí' : 'NO'));

        $problemas = [];

        if (($estado['resultado'] ?? '') !== 'ok') {
            $problemas[] = 'El último respaldo falló: '.($estado['detalle'] ?? 'sin detalle');
        }

        if ($horas > $limite) {
            $problemas[] = "Han pasado {$horas} horas desde el último respaldo (el límite son {$limite}).";
        }

        // Un respaldo que se queda en el mismo disco no protege de lo que de
        // verdad pasa: que el disco muera o que borren la máquina.
        if (! ($estado['fuera_del_servidor'] ?? false)) {
            $problemas[] = 'El respaldo no salió del servidor. Configura BACKUP_GCS_BUCKET.';
        }

        if ($problemas === []) {
            $this->newLine();
            $this->info('✓ Los respaldos están al día.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($problemas as $problema) {
            $this->error('✗ '.$problema);
        }

        return self::FAILURE;
    }

    private function tamano(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i = (int) min(floor(log($bytes, 1024)), count($unidades) - 1);

        return round($bytes / 1024 ** $i, 1).' '.$unidades[$i];
    }
}
