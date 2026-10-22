<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asocia todos los productos de una empresa a las sedes indicadas.
 *
 * Solo crea la fila producto↔sede en `product_locations`, igual que la
 * importación de productos: sin existencias. Las cantidades viven en
 * `inventory_movements` y este comando no crea ningún movimiento, así que
 * todo queda con stock 0 en las sedes nuevas.
 *
 * Las sedes se piden por código y son obligatorias: nunca se asume «todas».
 * Asociar a una sede equivocada cuesta más deshacerlo que volver a correr el
 * comando, así que un código que no exista hace fallar todo antes de escribir.
 *
 * Las asociaciones que ya existían se dejan como están, incluido su `active`.
 *
 * Sin `--aplicar` no toca nada: solo muestra qué haría.
 */
class AsociarProductosASedes extends Command
{
    protected $signature = 'productos:asociar-sedes
        {--empresa= : Nombre (o parte) o id de la empresa}
        {--sedes= : Códigos de las sedes, separados por coma}
        {--aplicar : Escribe los cambios. Sin esta opción solo muestra}';

    protected $description = 'Asocia todos los productos de una empresa a las sedes indicadas, sin cargar existencias';

    public function handle(): int
    {
        $empresa = $this->resolverEmpresa();

        if (! $empresa) {
            return self::FAILURE;
        }

        $todas = Location::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $elegidas = $this->resolverSedes($todas);

        if (! $elegidas) {
            return self::FAILURE;
        }

        $intactas = $todas->whereNotIn('id', $elegidas->pluck('id'));
        $productos = $this->productos($empresa);

        $this->info("Empresa: {$empresa->name} (id {$empresa->id})");
        $this->newLine();
        $this->line('<options=bold>Sedes que se van a asociar</>');
        foreach ($elegidas as $sede) {
            $this->line("   {$sede->code} — {$sede->name}");
        }
        $this->line('<options=bold>Sedes que NO se tocan</>');
        foreach ($intactas as $sede) {
            $this->line("   {$sede->code} — {$sede->name}");
        }
        if ($intactas->isEmpty()) {
            $this->line('   (ninguna)');
        }

        $this->newLine();
        $this->line("Productos de la empresa a asociar: {$productos->count()}");

        if (! $this->option('aplicar')) {
            $this->table(
                ['Sede', 'Ya asociados', 'Faltan'],
                $elegidas->map(function (Location $sede) use ($productos) {
                    $ya = $this->yaAsociados($sede, $productos);

                    return [$sede->code, $ya, $productos->count() - $ya];
                })->values()->all(),
            );

            $this->newLine();
            $this->warn('SIMULACIÓN: no se escribió nada.');
            $this->line('Para aplicarlo, repite el comando agregando  --aplicar');

            return self::SUCCESS;
        }

        $resultado = DB::transaction(function () use ($elegidas, $productos) {
            $resultado = [];

            foreach ($elegidas as $sede) {
                $creadas = 0;

                foreach ($productos as $productoId) {
                    $asociacion = ProductLocation::firstOrCreate(
                        ['product_id' => $productoId, 'location_id' => $sede->id],
                        ['active' => true],
                    );

                    if ($asociacion->wasRecentlyCreated) {
                        $creadas++;
                    }
                }

                $resultado[] = [$sede->code, $creadas, $productos->count() - $creadas];
            }

            return $resultado;
        });

        $this->newLine();
        $this->info('Listo.');
        $this->table(['Sede', 'Creadas', 'Ya existían'], $resultado);

        return self::SUCCESS;
    }

    // --------------------------------------------------------- qué asociar

    /**
     * Ids de los productos que se asocian.
     *
     * Entran los inactivos: pueden reactivarse, y entonces ya tienen su sede.
     * No entran los de tipo «variable»: el padre de las variantes no se
     * almacena ni se vende, lo que se almacena son sus variantes, que sí
     * entran (son productos normales con `parent_product_id`).
     *
     * Sin global scopes también desaparece el de borrado lógico, así que los
     * borrados se excluyen a mano: asociarlos solo sería basura.
     *
     * @return Collection<int, int>
     */
    private function productos(Company $empresa): Collection
    {
        return Product::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->whereNull('deleted_at')
            ->where('type', '!=', 'variable')
            ->orderBy('id')
            ->pluck('id');
    }

    private function yaAsociados(Location $sede, Collection $productos): int
    {
        return ProductLocation::query()
            ->where('location_id', $sede->id)
            ->whereIn('product_id', $productos)
            ->count();
    }

    // --------------------------------------------------------- resolución

    /**
     * Las sedes de --sedes, todas o ninguna.
     *
     * @param  Collection<int, Location>  $todas
     * @return Collection<int, Location>|null
     */
    private function resolverSedes(Collection $todas): ?Collection
    {
        $codigos = collect(explode(',', (string) $this->option('sedes')))
            ->map(fn ($c) => trim($c))
            ->filter(fn ($c) => $c !== '')
            ->unique()
            ->values();

        if ($codigos->isEmpty()) {
            $this->error('Falta --sedes con los códigos separados por coma. No se asume «todas».');
            $this->listarSedes($todas);

            return null;
        }

        $elegidas = collect();
        $noEncontradas = [];

        foreach ($codigos as $codigo) {
            $sede = $todas->first(fn (Location $l) => mb_strtoupper(trim((string) $l->code)) === mb_strtoupper($codigo));

            $sede ? $elegidas->push($sede) : $noEncontradas[] = $codigo;
        }

        if ($noEncontradas) {
            $this->error('Esta empresa no tiene sede con código: '.implode(', ', $noEncontradas).'. No se escribió nada.');
            $this->listarSedes($todas);

            return null;
        }

        return $elegidas->unique('id')->values();
    }

    private function listarSedes(Collection $todas): void
    {
        $this->newLine();
        $this->line('Sedes de la empresa (código — nombre):');

        foreach ($todas as $sede) {
            $this->line("   {$sede->code} — {$sede->name}");
        }
    }

    private function resolverEmpresa(): ?Company
    {
        $buscado = (string) $this->option('empresa');

        if ($buscado === '') {
            $this->error('Falta --empresa. Ejemplo: --empresa="MIRADOR CENTRAL"');
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
            // Elegir una al azar podria llenar de sedes el catalogo equivocado.
            $this->error("«{$buscado}» coincide con más de una empresa. Usa el id:");
            foreach ($empresas as $e) {
                $this->line("   {$e->id} — {$e->name}");
            }

            return null;
        }

        return $empresas->first();
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
