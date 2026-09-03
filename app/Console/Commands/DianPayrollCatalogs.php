<?php

namespace App\Console\Commands;

use App\Models\Dian\CompanyConfig;
use App\Services\Dian\DianApiClient;
use Illuminate\Console\Command;

/**
 * Consulta a apidian los catalogos que necesita la nomina electronica.
 *
 * Existe porque los ids del payload (tipo de trabajador, tipo de contrato,
 * periodicidad, medio de pago, deducciones de ley) los numera apidian
 * internamente y no tienen por que coincidir con los codigos de la DIAN.
 * Adivinarlos es la forma mas rapida de coleccionar rechazos.
 *
 * Usa la URL y el token que la empresa ya tiene guardados, para no andar
 * copiando credenciales a mano.
 */
class DianPayrollCatalogs extends Command
{
    protected $signature = 'dian:payroll-catalogs
        {--company= : ID de la empresa (usa su URL y token de apidian)}
        {--endpoint=/reports/master/database : Ruta del catalogo maestro}
        {--table=* : Limitar a estas tablas (por defecto, las de nomina)}';

    protected $description = 'Trae de apidian los catalogos de nomina electronica para confirmar los ids del payload';

    /** Las que usa PayrollCatalog. */
    private const TABLAS_NOMINA = [
        'type_workers',
        'sub_type_workers',
        'type_contracts',
        'payroll_periods',
        'type_law_deductions',
        'payroll_type_document_identifications',
        'payment_methods',
    ];

    public function handle(): int
    {
        $companyId = (int) $this->option('company');

        $config = $companyId > 0
            ? CompanyConfig::query()->where('company_id', $companyId)->first()
            : CompanyConfig::query()->whereNotNull('api_token')->first();

        if (! $config) {
            $this->error('No encontré configuración DIAN. Usa --company=ID.');

            return self::FAILURE;
        }

        if (! $config->api_token) {
            $this->error("La empresa {$config->company_id} no tiene token de apidian.");

            return self::FAILURE;
        }

        $url = $config->api_url ?: config('services.dian.api_url');
        $this->info("Consultando {$url}{$this->option('endpoint')} (empresa {$config->company_id})");
        $this->newLine();

        $resultado = (new DianApiClient($config))->masterDatabase($this->option('endpoint'));

        if (! $resultado['ok']) {
            $this->error('La consulta falló: '.($resultado['error'] ?? 'sin detalle'));
            $this->line('Si la ruta no es esa, pásala con --endpoint=/la/que/sea');

            return self::FAILURE;
        }

        $data = $resultado['data'] ?? [];
        $tablas = $this->option('table') ?: self::TABLAS_NOMINA;
        $encontradas = 0;

        foreach ($tablas as $tabla) {
            $filas = $this->buscarTabla($data, $tabla);

            if ($filas === null) {
                $this->warn("· {$tabla}: no vino en la respuesta");

                continue;
            }

            $encontradas++;
            $this->newLine();
            $this->line("<fg=cyan>{$tabla}</>");

            $this->table(
                ['id', 'nombre / código'],
                collect($filas)->map(fn ($f) => [
                    data_get($f, 'id', '—'),
                    data_get($f, 'name') ?? data_get($f, 'code') ?? json_encode($f),
                ])->take(40)->all(),
            );
        }

        if ($encontradas === 0) {
            $this->newLine();
            $this->warn('No apareció ninguna tabla de nómina. Estas son las claves que sí trajo la respuesta:');
            $this->line(implode(', ', array_slice(array_keys((array) $data), 0, 40)));
        }

        return self::SUCCESS;
    }

    /**
     * Las respuestas de apidian a veces anidan las tablas bajo otra clave, asi
     * que se busca por nombre en vez de asumir la forma.
     */
    private function buscarTabla(mixed $data, string $tabla): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data[$tabla]) && is_array($data[$tabla])) {
            return $data[$tabla];
        }

        foreach ($data as $valor) {
            if (is_array($valor)) {
                $encontrado = $this->buscarTabla($valor, $tabla);

                if ($encontrado !== null) {
                    return $encontrado;
                }
            }
        }

        return null;
    }
}
