<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\SaleInvoice;
use App\Services\Dian\DianInvoiceSender;
use App\Services\Dian\InvoiceRenumberer;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Renumera con la resolución de producción las facturas que salieron con otra
 * —típicamente la de pruebas de la habilitación— y las envía a la DIAN.
 *
 * Dos fases, cada una con su opción:
 *
 *   --aplicar  Renumera. Toma las facturas del prefijo --de que la DIAN nunca
 *              aceptó, en el orden en que se crearon, y les da el siguiente
 *              número de --a. Si la resolución origen es la de habilitación, al
 *              final la desactiva para que nadie vuelva a numerar con ella.
 *
 *   --enviar   Transmite las facturas contabilizadas de --a que están
 *              pendientes, con la fecha y hora del envío (la DIAN rechaza
 *              fechas de emisión atrasadas, FAD09e). La fecha guardada, el
 *              asiento y el IVA se quedan como estaban.
 *
 * Sin --aplicar no escribe ni envía nada, tampoco con --enviar: muestra qué
 * haría. Para renumerar y enviar en una sola corrida: --aplicar --enviar. En cualquier caso
 * deja un CSV en storage/app/private/correcciones con el número viejo y el
 * nuevo de cada factura, que es lo que hay que tener a mano si un cliente llama
 * con la factura impresa con el número anterior.
 */
class CorregirNumeracionFacturas extends Command
{
    protected $signature = 'dian:corregir-numeracion
        {--empresa= : Nombre (o parte) o id de la empresa}
        {--de=SETP : Prefijo de la resolución con que quedaron mal numeradas}
        {--a= : Prefijo de la resolución de producción con que se renumeran}
        {--aplicar : Renumera de verdad. Sin esta opción solo muestra}
        {--enviar : Envía a la DIAN las pendientes de la resolución destino}
        {--limite=0 : Máximo de facturas a enviar en esta corrida (0 = todas)}
        {--pausa=500 : Milisegundos de espera entre envíos}
        {--si : No pedir confirmación}';

    protected $description = 'Renumera con la resolución de producción las facturas mal numeradas y las envía a la DIAN';

    /** Fallos seguidos sin llegar a la DIAN antes de abortar el envío. */
    private const FALLOS_SEGUIDOS_MAX = 5;

    public function handle(InvoiceRenumberer $renumerador): int
    {
        $empresa = $this->resolverEmpresa();
        if (! $empresa) {
            return self::FAILURE;
        }

        // Sin empresa en contexto, los scopes por empresa de los modelos que
        // usa el envío (cliente, productos, impuestos) no saben a quién mirar.
        app(CurrentCompany::class)->set($empresa);

        $destino = $this->resolverResolucion($empresa, (string) $this->option('a'), 'destino (--a)');
        if (! $destino) {
            return self::FAILURE;
        }

        if ($destino->isHabilitacion()) {
            $this->error("{$destino->prefix} es la resolución de pruebas: no sirve como destino.");

            return self::FAILURE;
        }

        if (! $destino->active) {
            $this->error("La resolución destino {$destino->prefix} está inactiva.");

            return self::FAILURE;
        }

        $this->info("Empresa: {$empresa->name} (id {$empresa->id})");
        $this->info("Destino: {$destino->prefix} · Res. {$destino->resolution_number} · rango {$destino->range_from}–{$destino->range_to}");
        $this->newLine();

        $origen = $this->resolverResolucion($empresa, (string) $this->option('de'), 'origen (--de)', obligatoria: false);

        if ($origen && $origen->id === $destino->id) {
            $this->error('Origen y destino son la misma resolución.');

            return self::FAILURE;
        }

        if ($origen && ! $this->faseRenumerar($renumerador, $empresa, $origen, $destino)) {
            return self::FAILURE;
        }

        if ($this->option('enviar')) {
            return $this->faseEnviar($empresa, $destino);
        }

        return self::SUCCESS;
    }

    private function faseRenumerar(InvoiceRenumberer $renumerador, Company $empresa, Resolution $origen, Resolution $destino): bool
    {
        $this->info("== Renumerar {$origen->prefix} → {$destino->prefix} ==");

        foreach ($renumerador->bloqueadas($origen) as $fila) {
            $this->warn("   No se toca {$fila['factura']->fullNumber()}: {$fila['motivo']}.");
        }

        $facturas = $renumerador->candidatas($origen);

        if ($facturas->isEmpty()) {
            $this->info("   No hay facturas {$origen->prefix} por renumerar.");
            $this->desactivarOrigenSiAplica($origen);
            $this->newLine();

            return true;
        }

        $desde = $renumerador->siguienteNumero($destino);
        $hasta = $desde + $facturas->count() - 1;

        if ($hasta > (int) $destino->range_to) {
            $this->error(sprintf(
                'No alcanza el rango: hacen falta %d números desde %d y la resolución llega a %d.',
                $facturas->count(), $desde, $destino->range_to,
            ));

            return false;
        }

        $this->line(sprintf(
            '   %d factura(s) · tomarían %s%d a %s%d',
            $facturas->count(), $destino->prefix, $desde, $destino->prefix, $hasta,
        ));
        $this->mostrarResumen($facturas);

        if (! $this->option('aplicar')) {
            $previstas = $facturas->values()->map(fn (SaleInvoice $f, int $i) => $this->filaCsv(
                $f, $f->fullNumber(), $destino->prefix.($desde + $i), 'simulación',
            ));
            $archivo = $this->guardarCsv($empresa, 'renumeracion-simulacion', $previstas);

            $this->newLine();
            $this->warn('SIMULACIÓN: no se escribió nada.');
            $this->line("   Lista prevista: {$archivo}");
            $this->line('   Para aplicarlo, repite el comando agregando  --aplicar');
            $this->newLine();

            return true;
        }

        if (! $this->option('si') && ! $this->confirm("¿Renumerar {$facturas->count()} facturas?", false)) {
            $this->warn('Cancelado.');

            return false;
        }

        $filas = collect();
        $bar = $this->output->createProgressBar($facturas->count());

        foreach ($facturas as $factura) {
            $viejo = $factura->fullNumber();

            try {
                $cambio = $renumerador->renumerar($factura, $destino);
                $filas->push($this->filaCsv($factura, $cambio['viejo'], $cambio['nuevo'], 'renumerada'));
            } catch (\Throwable $e) {
                $filas->push($this->filaCsv($factura, $viejo, '', 'error: '.$e->getMessage()));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $archivo = $this->guardarCsv($empresa, 'renumeracion', $filas);
        $errores = $filas->filter(fn ($f) => str_starts_with($f['resultado'], 'error'))->count();

        $this->info(sprintf('   Renumeradas: %d · con error: %d', $filas->count() - $errores, $errores));
        $this->line("   Detalle: {$archivo}");

        if ($errores === 0) {
            $this->desactivarOrigenSiAplica($origen);
        } else {
            $this->warn("   {$origen->prefix} se deja activa hasta resolver los errores.");
        }

        $this->newLine();

        return $errores === 0;
    }

    private function faseEnviar(Company $empresa, Resolution $destino): int
    {
        $this->info("== Enviar a la DIAN las {$destino->prefix} pendientes ==");

        $limite = max(0, (int) $this->option('limite'));

        $facturas = SaleInvoice::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $empresa->id)
            ->where('prefix', $destino->prefix)
            ->where('invoice_kind', 'electronic')
            ->where('status', 'posted')
            ->where('dian_status', SaleInvoice::DIAN_PENDING)
            ->orderBy('number')
            ->when($limite > 0, fn ($q) => $q->limit($limite))
            ->get();

        if ($facturas->isEmpty()) {
            $this->info('   No hay facturas pendientes de envío.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '   %d factura(s): %s a %s · se envían con la fecha y hora del envío',
            $facturas->count(), $facturas->first()->fullNumber(), $facturas->last()->fullNumber(),
        ));

        if (! $this->option('aplicar')) {
            $this->warn('   SIMULACIÓN: no se envió nada. Agrega  --aplicar  para enviar.');
            $this->line('   (En simulación no cuentan las que se renumerarían: al aplicar, se envían también.)');

            return self::SUCCESS;
        }

        if (! $this->option('si') && ! $this->confirm("¿Enviar {$facturas->count()} facturas a la DIAN?", false)) {
            $this->warn('Cancelado.');

            return self::FAILURE;
        }

        $sender = app(DianInvoiceSender::class);
        $pausa = max(0, (int) $this->option('pausa'));
        $filas = collect();
        $fallosSeguidos = 0;
        $aceptadas = $rechazadas = $sinRespuesta = 0;

        foreach ($facturas as $factura) {
            try {
                $r = $sender->send($factura, now());
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'message' => $e->getMessage(), 'reached_dian' => false];
            }

            match (true) {
                $r['ok'] => $aceptadas++,
                $r['reached_dian'] => $rechazadas++,
                default => $sinRespuesta++,
            };

            $estado = $r['ok'] ? 'aceptada' : ($r['reached_dian'] ? 'rechazada' : 'sin respuesta');
            $this->line(sprintf('   %-14s %-13s %s', $factura->fullNumber(), $estado, $r['ok'] ? '' : mb_strimwidth((string) $r['message'], 0, 110, '…')));
            $filas->push($this->filaCsv($factura, $factura->fullNumber(), $factura->fullNumber(), $estado.($r['ok'] ? '' : ': '.$r['message'])));

            // Si la DIAN ni siquiera contesta varias veces seguidas, el problema
            // es de configuración o de red, no de cada factura: seguir solo
            // llena de «rechazadas» facturas que no tienen nada malo.
            $fallosSeguidos = ($r['ok'] || $r['reached_dian']) ? 0 : $fallosSeguidos + 1;
            if ($fallosSeguidos >= self::FALLOS_SEGUIDOS_MAX) {
                $this->error('   '.self::FALLOS_SEGUIDOS_MAX.' envíos seguidos sin respuesta de la DIAN. Se detiene el envío.');
                break;
            }

            if ($pausa > 0) {
                usleep($pausa * 1000);
            }
        }

        $archivo = $this->guardarCsv($empresa, 'envio', $filas);

        $this->newLine();
        $this->info("   Aceptadas: {$aceptadas} · rechazadas: {$rechazadas} · sin respuesta: {$sinRespuesta}");
        $this->line("   Detalle: {$archivo}");

        return ($rechazadas + $sinRespuesta) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Con la empresa en producción, la resolución de pruebas no le sirve a
     * nadie y dejarla activa es como empezó todo.
     */
    private function desactivarOrigenSiAplica(Resolution $origen): void
    {
        if (! $origen->active || ! $origen->bloqueadaEnProduccion()) {
            return;
        }

        if (! $this->option('aplicar')) {
            $this->line("   Al aplicar se desactivará la resolución de pruebas {$origen->prefix}.");

            return;
        }

        $origen->update(['active' => false]);
        LocationResolution::query()->where('dian_resolution_id', $origen->id)->update(['active' => false]);

        $this->info("   Resolución de pruebas {$origen->prefix} desactivada.");
    }

    /** @param  Collection<int, SaleInvoice>  $facturas */
    private function mostrarResumen(Collection $facturas): void
    {
        $porEstado = $facturas->countBy(fn (SaleInvoice $f) => $f->status.' / '.($f->dian_status ?: 'sin estado DIAN'));

        foreach ($porEstado as $estado => $n) {
            $this->line("     {$estado}: {$n}");
        }
    }

    private function filaCsv(SaleInvoice $f, string $viejo, string $nuevo, string $resultado): array
    {
        return [
            'factura_id' => $f->id,
            'fecha' => $f->date?->format('Y-m-d'),
            'sede_id' => $f->location_id,
            'cliente_id' => $f->third_party_id,
            'total' => $f->total,
            'estado' => $f->status,
            'numero_anterior' => $viejo,
            'numero_nuevo' => $nuevo,
            'resultado' => $resultado,
        ];
    }

    private function guardarCsv(Company $empresa, string $tipo, Collection $filas): string
    {
        $ruta = sprintf('correcciones/%d-%s-%s.csv', $empresa->id, $tipo, now()->format('Ymd-His'));

        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_keys($filas->first() ?? ['sin_datos' => '']));
        foreach ($filas as $fila) {
            fputcsv($h, $fila);
        }
        rewind($h);
        Storage::disk('local')->put($ruta, stream_get_contents($h));
        fclose($h);

        return Storage::disk('local')->path($ruta);
    }

    private function resolverResolucion(Company $empresa, string $prefijo, string $rol, bool $obligatoria = true): ?Resolution
    {
        if ($prefijo === '') {
            if ($obligatoria) {
                $this->error("Falta la resolución {$rol}.");
                $this->listarResoluciones($empresa);
            }

            return null;
        }

        $resoluciones = Resolution::query()
            ->withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('kind', Resolution::KIND_ELECTRONIC)
            ->where('document_type_id', 1)
            ->where('prefix', $prefijo)
            ->get();

        if ($resoluciones->count() === 1) {
            return $resoluciones->first();
        }

        if ($resoluciones->isEmpty() && ! $obligatoria) {
            $this->line("No hay resolución {$rol} {$prefijo}: se omite la renumeración.");

            return null;
        }

        $this->error($resoluciones->isEmpty()
            ? "La empresa no tiene una resolución electrónica de factura con prefijo «{$prefijo}»."
            : "Hay más de una resolución con prefijo «{$prefijo}»: revísalas antes de seguir.");
        $this->listarResoluciones($empresa);

        return null;
    }

    private function listarResoluciones(Company $empresa): void
    {
        $this->line('Resoluciones electrónicas de factura de la empresa:');

        Resolution::query()
            ->withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('kind', Resolution::KIND_ELECTRONIC)
            ->where('document_type_id', 1)
            ->orderBy('prefix')
            ->get()
            ->each(fn (Resolution $r) => $this->line(sprintf(
                '   %-6s Res. %-16s %d–%d %s',
                $r->prefix, $r->resolution_number, $r->range_from, $r->range_to, $r->active ? '' : '(inactiva)',
            )));
    }

    private function resolverEmpresa(): ?Company
    {
        $buscado = (string) $this->option('empresa');

        if ($buscado === '') {
            $this->error('Falta --empresa. Ejemplo: --empresa=TRONITEC');

            return null;
        }

        $empresas = Company::withoutGlobalScopes()
            ->when(
                is_numeric($buscado),
                fn ($q) => $q->where('id', (int) $buscado),
                fn ($q) => $q->where('name', 'ilike', '%'.$buscado.'%'),
            )
            ->get();

        if ($empresas->count() !== 1) {
            $this->error($empresas->isEmpty()
                ? "No hay ninguna empresa que coincida con «{$buscado}»."
                : "«{$buscado}» coincide con más de una empresa. Usa el id:");

            foreach ($empresas as $e) {
                $this->line("   {$e->id} — {$e->name}");
            }

            return null;
        }

        return $empresas->first();
    }
}
