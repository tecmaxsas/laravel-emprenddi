<?php

namespace App\Console\Commands;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Services\Cash\CashSessionSummary;
use App\Support\PaymentMethodOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara el turno de caja de los cobros que quedaron mal atribuidos.
 *
 * Dos errores dejaron plata fuera del arqueo, y este comando arregla lo que
 * ya estaba registrado cuando se corrigieron:
 *
 *  1. Los pagos de facturas de venta heredaban el turno DE LA FACTURA. Un
 *     abono de hoy sobre una factura de la semana pasada aterrizaba en aquel
 *     turno, ya cerrado; y el cobro de una factura hecha fuera del POS no
 *     caía en ninguno.
 *
 *  2. Los anticipos nunca guardaban su turno, así que el efectivo de un
 *     anticipo no entraba en «Esperado en caja» por ningún lado.
 *
 * El criterio para reasignar es la **fecha en que se registró el movimiento**:
 * si se creó mientras este turno estaba abierto, la plata entró en este turno.
 * No se usa la fecha del documento, que el usuario puede escribir a mano.
 *
 * Sin `--aplicar` no toca nada: solo muestra qué cambiaría.
 */
class ReasignarMovimientosDeCaja extends Command
{
    protected $signature = 'caja:reasignar
        {--empresa= : Nombre (o parte) o id de la empresa}
        {--turno= : Id del turno; por defecto, el que esté abierto}
        {--aplicar : Escribe los cambios. Sin esta opción solo muestra}';

    protected $description = 'Atribuye al turno correcto los cobros y anticipos que quedaron sueltos';

    public function handle(): int
    {
        $empresa = $this->resolverEmpresa();

        if (! $empresa) {
            return self::FAILURE;
        }

        $turno = $this->resolverTurno($empresa);

        if (! $turno) {
            return self::FAILURE;
        }

        $this->info("Empresa: {$empresa->name} (id {$empresa->id})");
        $this->info("Turno:   {$turno->id} · abierto {$turno->opened_at} · sede {$turno->location_id}");
        $this->newLine();

        $anticipos = $this->anticiposSueltos($empresa, $turno);
        $pagos = $this->pagosMalAtribuidos($empresa, $turno);

        if ($anticipos->isEmpty() && $pagos->isEmpty()) {
            $this->info('No hay nada que reasignar: todos los movimientos del turno ya están bien.');

            return self::SUCCESS;
        }

        $antes = app(CashSessionSummary::class)->compute($turno);

        $this->mostrar('Anticipos sin turno', $anticipos, $empresa->id);
        $this->mostrar('Pagos de venta atribuidos a otro turno', $pagos, $empresa->id);

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->warn('SIMULACIÓN: no se escribió nada.');
            $this->line('Para aplicarlo, repite el comando agregando  --aplicar');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($anticipos, $pagos, $turno) {
            if ($anticipos->isNotEmpty()) {
                CustomerAdvance::withoutGlobalScopes()
                    ->whereIn('id', $anticipos->pluck('id'))
                    ->update(['cash_register_session_id' => $turno->id]);
            }

            if ($pagos->isNotEmpty()) {
                Payment::withoutGlobalScopes()
                    ->whereIn('id', $pagos->pluck('id'))
                    ->update(['cash_register_session_id' => $turno->id]);
            }
        });

        $despues = app(CashSessionSummary::class)->compute($turno->fresh());

        $this->newLine();
        $this->info('Listo. Esperado en caja:');
        $this->line('   antes:   $'.number_format($antes['expected_cash'], 0, ',', '.'));
        $this->line('   ahora:   $'.number_format($despues['expected_cash'], 0, ',', '.'));
        $this->line('   cambio:  $'.number_format(
            $despues['expected_cash'] - $antes['expected_cash'], 0, ',', '.'
        ));

        return self::SUCCESS;
    }

    // --------------------------------------------------------- qué reasignar

    /**
     * Anticipos recibidos mientras este turno estaba abierto y sin turno
     * asignado. No se tocan los que ya tienen uno: ese dato es de fiar.
     */
    private function anticiposSueltos(Company $empresa, CashRegisterSession $turno)
    {
        // Sin filtro de `deleted_at`: los anticipos no tienen borrado logico.
        return CustomerAdvance::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->whereNull('cash_register_session_id')
            ->where('created_at', '>=', $turno->opened_at)
            ->when($turno->closed_at, fn ($q) => $q->where('created_at', '<=', $turno->closed_at))
            ->get(['id', 'amount', 'payment_method', 'created_at']);
    }

    /**
     * Cobros de facturas de venta registrados durante este turno pero
     * atribuidos a otro —o a ninguno—.
     *
     * Se excluyen las aplicaciones de anticipo: esa operación no mueve dinero
     * y el resumen del turno ya no la cuenta. Reasignarlas sería ruido.
     */
    private function pagosMalAtribuidos(Company $empresa, CashRegisterSession $turno)
    {
        return Payment::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->whereNull('deleted_at')
            ->where('paymentable_type', SaleInvoice::class)
            ->whereNull('customer_advance_id')
            ->where('created_at', '>=', $turno->opened_at)
            ->when($turno->closed_at, fn ($q) => $q->where('created_at', '<=', $turno->closed_at))
            ->where(function ($q) use ($turno) {
                $q->whereNull('cash_register_session_id')
                    ->orWhere('cash_register_session_id', '!=', $turno->id);
            })
            ->get(['id', 'amount', 'payment_method', 'created_at', 'cash_register_session_id']);
    }

    // --------------------------------------------------------- presentación

    private function mostrar(string $titulo, $filas, int $companyId): void
    {
        $this->newLine();
        $this->line("<options=bold>{$titulo}</>");

        if ($filas->isEmpty()) {
            $this->line('   (ninguno)');

            return;
        }

        $porMetodo = $filas->groupBy(fn ($f) => $f->payment_method ?: '(sin método)');

        $this->table(
            ['Método', 'Cantidad', 'Total'],
            $porMetodo->map(fn ($grupo, $metodo) => [
                $metodo === '(sin método)' ? $metodo : PaymentMethodOptions::nombre($metodo, $companyId),
                $grupo->count(),
                '$'.number_format($grupo->sum(fn ($f) => (float) $f->amount), 0, ',', '.'),
            ])->values()->all(),
        );

        $this->line('   TOTAL: $'.number_format(
            $filas->sum(fn ($f) => (float) $f->amount), 0, ',', '.'
        ));
    }

    // --------------------------------------------------------- resolución

    private function resolverEmpresa(): ?Company
    {
        $buscado = (string) $this->option('empresa');

        if ($buscado === '') {
            $this->error('Falta --empresa. Ejemplo: --empresa=AndyExpress');
            $this->listarEmpresas();

            return null;
        }

        $empresas = Company::withoutGlobalScopes()
            ->when(
                is_numeric($buscado),
                fn ($q) => $q->where('id', (int) $buscado),
                fn ($q) => $q->where('name', 'ilike', '%'.$buscado.'%'),
            )
            ->get();

        if ($empresas->isEmpty()) {
            $this->error("No hay ninguna empresa que coincida con «{$buscado}».");
            $this->listarEmpresas();

            return null;
        }

        if ($empresas->count() > 1) {
            // Elegir una al azar podria reparar la caja equivocada.
            $this->error("«{$buscado}» coincide con más de una empresa. Usa el id:");
            foreach ($empresas as $e) {
                $this->line("   {$e->id} — {$e->name}");
            }

            return null;
        }

        return $empresas->first();
    }

    private function resolverTurno(Company $empresa): ?CashRegisterSession
    {
        if ($id = $this->option('turno')) {
            $turno = CashRegisterSession::withoutGlobalScopes()
                ->where('company_id', $empresa->id)
                ->find((int) $id);

            if (! $turno) {
                $this->error("El turno {$id} no existe o es de otra empresa.");

                return null;
            }

            return $turno;
        }

        $abiertos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->orderByDesc('opened_at')
            ->get();

        if ($abiertos->isEmpty()) {
            $this->error('Esa empresa no tiene ninguna caja abierta. Pasa --turno=<id> si quieres reparar una ya cerrada.');

            return null;
        }

        if ($abiertos->count() > 1) {
            $this->error('Hay más de una caja abierta. Elige cuál con --turno=<id>:');
            foreach ($abiertos as $t) {
                $this->line("   {$t->id} — abierta {$t->opened_at} — sede {$t->location_id}");
            }

            return null;
        }

        return $abiertos->first();
    }

    private function listarEmpresas(): void
    {
        $this->newLine();
        $this->line('Empresas disponibles:');

        foreach (Company::withoutGlobalScopes()->orderBy('id')->get(['id', 'name']) as $e) {
            $this->line("   {$e->id} — {$e->name}");
        }
    }
}
