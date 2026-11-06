<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ThirdParty;
use App\Services\Sales\CustomerAdvanceService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Reaplica el saldo a favor de los clientes con la regla corregida.
 *
 * QUE ARREGLA.
 *
 * Hasta el 06/11/2026 los abonos se aplicaban SOLO a las facturas del
 * sistema: el saldo de apertura —lo que el cliente ya debía cuando la empresa
 * empezó a usar Emprenddi— lo saltaban por completo. Un cliente podía tener
 * saldo a favor sin usar mientras su deuda más vieja seguía intacta, que es
 * justo la que lleva más tiempo sin cobrarse.
 *
 * Este comando recorre los clientes de una empresa y vuelve a aplicar lo que
 * tengan a favor con la regla nueva: primero el saldo de apertura, después las
 * facturas de la más antigua a la más reciente.
 *
 * LO QUE **NO** HACE, Y ES IMPORTANTE SABERLO.
 *
 * No deshace aplicaciones ya hechas. Si un anticipo viejo se fue entero contra
 * facturas, ahí se queda: moverlo al saldo de apertura significaría borrar
 * cobros, reversar sus asientos y cambiar el estado de esas facturas. Eso es
 * reescribir contabilidad para arreglar un orden de imputación, y el remedio
 * sería peor.
 *
 * Así que esto corrige a los clientes que TIENEN SALDO A FAVOR SIN USAR. Para
 * el resto, la regla nueva aplica de aquí en adelante.
 *
 *   php artisan cartera:aplicar-abonos --nit=900123456 --dry-run
 *   php artisan cartera:aplicar-abonos --nit=900123456
 */
class AplicarAbonosPendientes extends Command
{
    protected $signature = 'cartera:aplicar-abonos
        {company? : ID de la empresa}
        {--nit= : NIT de la empresa (alternativa al ID)}
        {--nombre= : Parte del nombre de la empresa (alternativa al ID)}
        {--dry-run : Muestra qué haría, sin escribir nada}';

    protected $description = 'Aplica el saldo a favor de los clientes: primero al saldo de apertura, luego a las facturas';

    public function handle(CustomerAdvanceService $anticipos): int
    {
        $empresa = $this->resolverEmpresa();

        if (! $empresa) {
            return self::FAILURE;
        }

        $simulacion = (bool) $this->option('dry-run');

        $this->info(($simulacion ? '[SIMULACIÓN] ' : '').'Empresa: '.$empresa->name.' (id '.$empresa->id.')');
        $this->newLine();

        // El servicio y los modelos filtran por la empresa actual.
        app(CurrentCompany::class)->set($empresa);

        $clientes = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->whereNull('deleted_at')
            ->where('is_customer', true)
            ->orderBy('name')
            ->get();

        $filas = [];
        $totalAplicado = 0.0;

        foreach ($clientes as $cliente) {
            $aFavor = $anticipos->availableBalance($cliente);

            if ($aFavor <= 0.01) {
                continue;
            }

            $aperturaAntes = $cliente->saldoDeAperturaPendiente();

            if ($simulacion) {
                // Sin escribir: se calcula cuánto iría a la apertura y cuánto
                // quedaría para las facturas.
                $aLaApertura = round(min($aFavor, $aperturaAntes), 2);
                $resto = round($aFavor - $aLaApertura, 2);

                $filas[] = [
                    $cliente->name,
                    $this->plata($aFavor),
                    $this->plata($aperturaAntes),
                    $this->plata($aLaApertura),
                    $this->plata($resto),
                ];

                $totalAplicado += $aLaApertura;

                continue;
            }

            $aplicado = $anticipos->applyToPendingInvoices($cliente);
            $fresco = $cliente->fresh();

            $filas[] = [
                $cliente->name,
                $this->plata($aFavor),
                $this->plata($aperturaAntes),
                $this->plata($aperturaAntes - $fresco->saldoDeAperturaPendiente()),
                $this->plata($anticipos->availableBalance($fresco)),
            ];

            $totalAplicado += $aplicado;
        }

        if ($filas === []) {
            $this->line('Ningún cliente tiene saldo a favor sin usar. No hay nada que reaplicar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Cliente', 'Tenía a favor', 'Apertura pendiente', 'Se aplica a apertura', 'Queda a favor'],
            $filas,
        );

        $this->newLine();
        $this->info(($simulacion ? 'Se aplicarían ' : 'Aplicado: ').$this->plata($totalAplicado)
            .' en '.count($filas).' cliente(s).');

        if ($simulacion) {
            $this->newLine();
            $this->comment('Nada se escribió. Repite el comando sin --dry-run para aplicarlo.');
        }

        return self::SUCCESS;
    }

    protected function plata(float $monto): string
    {
        return '$'.number_format($monto, 0, ',', '.');
    }

    protected function resolverEmpresa(): ?Company
    {
        if ($id = $this->argument('company')) {
            return Company::withoutGlobalScopes()->find($id);
        }

        // `nit` y no `document_number`: esa columna no existe en `companies`.
        if ($nit = $this->option('nit')) {
            return Company::withoutGlobalScopes()->where('nit', $nit)->first();
        }

        if ($nombre = $this->option('nombre')) {
            $candidatas = Company::withoutGlobalScopes()
                ->where('name', 'ilike', '%'.$nombre.'%')
                ->get(['id', 'name', 'nit']);

            if ($candidatas->count() === 1) {
                return $candidatas->first();
            }

            if ($candidatas->isEmpty()) {
                $this->error("Ninguna empresa coincide con «{$nombre}».");

                return null;
            }

            // Varias coincidencias: se listan en vez de elegir una. Correr esto
            // sobre la empresa equivocada le mueve la cartera a otro negocio.
            $this->error('Hay varias empresas que coinciden. Repite con el ID:');

            foreach ($candidatas as $c) {
                $this->line("  {$c->id}  {$c->name}  (NIT {$c->nit})");
            }

            return null;
        }

        $this->error('Debes pasar el ID como argumento, o --nit=, o --nombre=.');

        return null;
    }
}
