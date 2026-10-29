<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El folio: lo que el huésped debe, renglón por renglón.
 *
 * Es el único sitio que escribe los totales de una estadía. Están en columnas
 * y no se suman al leer porque el mapa de recepción muestra el saldo de cada
 * habitación ocupada: sumar renglones en cada pintada sería una consulta por
 * habitación, y el mapa se repinta todo el día.
 *
 * Esa decisión tiene un costo: un total guardado puede quedar viejo. Por eso
 * no hay forma de tocar un renglón sin pasar por aquí, y todo lo que lo toca
 * recalcula al salir, en la misma transacción.
 *
 * LOS PRECIOS SON AL PÚBLICO (IVA incluido), igual que la tarifa del tipo de
 * habitación y que los extras del parqueadero. La base gravable se descompone
 * al guardar cada renglón, así que el folio ya trae la base y el impuesto
 * separados y la factura los copia tal cual. Así no hay dos cálculos de IVA
 * que puedan discrepar en centavos.
 */
class HotelFolio
{
    /**
     * Carga las noches que faltan, de la entrada hasta hoy.
     *
     * Es el pase nocturno. Un hotel cobra por noche dormida y el folio tiene
     * que reflejarlo mientras el huésped está adentro, no al final: el que
     * lleva cuatro noches y pide la cuenta a mitad de estadía tiene que verlas
     * cobradas, y recepción necesita saber cuánto va debiendo cada habitación
     * sin tener que simular un check-out.
     *
     * Idempotente: se puede llamar diez veces al día. Cada noche es un renglón
     * con su `night_date`, y la base tiene un índice único que lo garantiza
     * incluso si dos procesos corren a la vez.
     *
     * @return int cuántas noches nuevas quedaron cargadas
     */
    public function cargarNochesPendientes(Stay $estadia, Carbon|string|null $hasta = null): int
    {
        if (! $estadia->estaAbierta()) {
            return 0;
        }

        $primera = $estadia->check_in_at->copy()->startOfDay();

        // Hasta dónde cobrar. Por defecto hoy: la noche de hoy ya se está
        // durmiendo. Las noches futuras no se cobran por adelantado — si el
        // huésped se va antes, habría que devolverle plata ya facturada.
        $corte = $hasta
            ? ($hasta instanceof Carbon ? $hasta->copy() : Carbon::parse($hasta))->startOfDay()
            : now()->startOfDay();

        // Nunca más allá de lo pactado mientras la estadía siga abierta: si se
        // queda de más, es el check-out el que decide cuántas noches se cobran.
        $pactado = $estadia->expected_check_out?->copy()->startOfDay();

        if ($pactado && $corte->gt($pactado->copy()->subDay())) {
            $corte = $pactado->copy()->subDay();
        }

        if ($corte->lt($primera)) {
            // Llegó hoy y la salida es mañana: la noche de hoy sí se cobra.
            $corte = $primera;
        }

        $yaCargadas = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->noches()
            ->pluck('night_date')
            ->map(fn ($fecha) => Carbon::parse($fecha)->toDateString())
            ->all();

        $nuevas = 0;
        $noche = $primera->copy();

        while ($noche->lte($corte)) {
            if (! in_array($noche->toDateString(), $yaCargadas, true)) {
                $this->agregarNoche($estadia, $noche);
                $nuevas++;
            }

            $noche->addDay();
        }

        return $nuevas;
    }

    /**
     * Cobra una noche concreta.
     *
     * La tarifa sale de la estadía y no del tipo de habitación: lo pactado al
     * entrar es lo que se cobra, aunque la lista de precios suba al día
     * siguiente.
     */
    public function agregarNoche(Stay $estadia, Carbon|string $noche, ?float $tarifa = null): StayCharge
    {
        $noche = ($noche instanceof Carbon ? $noche->copy() : Carbon::parse($noche))->startOfDay();
        $tarifa ??= (float) $estadia->rate_per_night;

        $impuesto = $this->impuestoDelAlojamiento($estadia);

        return $this->guardar($estadia, [
            'kind' => StayCharge::KIND_ROOM,
            'product_id' => app(HotelProductProvisioner::class)->ensure($estadia->company)->id,
            'charge_date' => $noche->toDateString(),
            'night_date' => $noche->toDateString(),
            'description' => sprintf(
                'Alojamiento %s — noche del %s',
                $estadia->room?->number ? 'hab. '.$estadia->room->number : '',
                $noche->format('d/m/Y'),
            ),
            'quantity' => 1,
            'unit_price' => $tarifa,
            'tax_id' => $impuesto?->id,
            'tax_rate' => $impuesto ? (float) $impuesto->rate : 0.0,
        ]);
    }

    /**
     * Carga un consumo: el minibar, la lavandería, el almuerzo del restaurante.
     *
     * @param  array{product_id?: int|null, description?: string, quantity?: float,
     *               unit_price?: float, tax_id?: int|null, kind?: string,
     *               charge_date?: string|null}  $datos
     */
    public function agregarConsumo(Stay $estadia, array $datos): StayCharge
    {
        $this->exigirAbierta($estadia);

        $producto = isset($datos['product_id']) && $datos['product_id']
            ? Product::query()->where('company_id', $estadia->company_id)->find($datos['product_id'])
            : null;

        $descripcion = trim((string) ($datos['description'] ?? $producto?->name ?? ''));

        if ($descripcion === '') {
            throw new RuntimeException('El cargo necesita una descripción para que el huésped sepa qué está pagando.');
        }

        $cantidad = (float) ($datos['quantity'] ?? 1);

        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad del cargo tiene que ser mayor que cero.');
        }

        $precio = (float) ($datos['unit_price'] ?? $producto?->default_sale_price ?? 0);

        if ($precio <= 0) {
            throw new RuntimeException('El cargo necesita un valor. Un renglón en cero no se cobra y confunde el folio.');
        }

        // El impuesto del consumo es el del producto, NO el del alojamiento:
        // la exención del huésped extranjero cubre el hospedaje, no la cerveza
        // del minibar.
        $impuestoId = $datos['tax_id'] ?? $producto?->default_sale_tax_id;
        $impuesto = $impuestoId
            ? Tax::query()->where('company_id', $estadia->company_id)->find($impuestoId)
            : null;

        $kind = in_array($datos['kind'] ?? '', [StayCharge::KIND_PRODUCT, StayCharge::KIND_SERVICE], true)
            ? $datos['kind']
            : ($producto && $producto->type !== 'service' ? StayCharge::KIND_PRODUCT : StayCharge::KIND_SERVICE);

        return $this->guardar($estadia, [
            'kind' => $kind,
            'product_id' => $producto?->id,
            'charge_date' => $datos['charge_date'] ?? now()->toDateString(),
            'night_date' => null,
            'description' => $descripcion,
            'quantity' => $cantidad,
            'unit_price' => $precio,
            'tax_id' => $impuesto?->id,
            'tax_rate' => $impuesto ? (float) $impuesto->rate : 0.0,
        ]);
    }

    /**
     * Un ajuste a la baja: la cortesía, el descuento pactado, la noche que no
     * se cobra porque el aire no funcionó.
     *
     * Entra como renglón negativo del folio y al facturar se convierte en el
     * descuento global de la factura, que la DIAN sí acepta —baja la base
     * gravable y el IVA se recalcula sobre lo descontado—. Una línea de
     * factura con valor negativo, en cambio, la rechaza.
     *
     * Lleva el impuesto de lo que está rebajando (por defecto el del
     * alojamiento, que es el caso normal). Sin impuesto, la rebaja bajaría el
     * total pero no el IVA, y el hotel terminaría pagándole a la DIAN el IVA
     * de una plata que no cobró.
     */
    public function agregarAjuste(
        Stay $estadia,
        string $concepto,
        float $monto,
        ?int $taxId = null,
    ): StayCharge {
        $this->exigirAbierta($estadia);

        $concepto = trim($concepto);

        if ($concepto === '') {
            throw new RuntimeException('Un ajuste al folio tiene que decir por qué. Es lo que se le explica al huésped.');
        }

        $monto = abs($monto);

        if ($monto <= 0) {
            throw new RuntimeException('El ajuste tiene que tener un valor.');
        }

        $impuesto = $taxId
            ? Tax::query()->where('company_id', $estadia->company_id)->find($taxId)
            : $this->impuestoDelAlojamiento($estadia);

        return $this->guardar($estadia, [
            'kind' => StayCharge::KIND_ADJUSTMENT,
            'product_id' => null,
            'charge_date' => now()->toDateString(),
            'night_date' => null,
            'description' => $concepto,
            'quantity' => 1,
            'unit_price' => -$monto,
            'tax_id' => $impuesto?->id,
            'tax_rate' => $impuesto ? (float) $impuesto->rate : 0.0,
        ]);
    }

    /** Quita un renglón del folio. Solo mientras la estadía esté abierta. */
    public function quitar(StayCharge $cargo): void
    {
        $estadia = $cargo->stay;

        if (! $estadia) {
            throw new RuntimeException('El cargo no pertenece a ninguna estadía.');
        }

        $this->exigirAbierta($estadia);

        DB::transaction(function () use ($cargo, $estadia) {
            $cargo->delete();
            $this->recalcular($estadia);
        });
    }

    /**
     * Vuelve a sumar el folio y escribe los totales.
     *
     * Un ajuste negativo no entra en el total como un renglón más: se suma
     * aparte en `discount_total`, porque es lo que al facturar se convierte en
     * el descuento global de la factura. Mezclarlo con los consumos haría que
     * la factura no supiera qué descontar.
     */
    public function recalcular(Stay $estadia): Stay
    {
        $cargos = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->get(['kind', 'subtotal', 'tax_amount', 'total']);

        $alojamiento = 0.0;
        $consumos = 0.0;
        $descuento = 0.0;
        $impuestos = 0.0;

        foreach ($cargos as $cargo) {
            $total = (float) $cargo->total;
            $impuestos += (float) $cargo->tax_amount;

            if ($cargo->kind === StayCharge::KIND_ROOM) {
                $alojamiento += $total;

                continue;
            }

            if ($cargo->kind === StayCharge::KIND_ADJUSTMENT) {
                // Los ajustes son a la baja; si alguno quedara positivo, es un
                // cargo más y se trata como consumo.
                $total < 0 ? $descuento += abs($total) : $consumos += $total;

                continue;
            }

            $consumos += $total;
        }

        $estadia->forceFill([
            'room_total' => round($alojamiento, 2),
            'consumption_total' => round($consumos, 2),
            'discount_total' => round($descuento, 2),
            'tax_total' => round($impuestos, 2),
            'total' => round($alojamiento + $consumos - $descuento, 2),
        ])->save();

        return $estadia;
    }

    /**
     * Los renglones que van a la factura, ya agrupados como el huésped los
     * quiere ver: las noches en una sola línea y cada consumo en la suya.
     *
     * Cinco noches iguales en cinco líneas de factura es un documento que
     * nadie lee. En el folio sí van separadas —ahí se revisan y se discuten—,
     * pero la factura es el resumen.
     *
     * @return array{lineas: list<array<string, mixed>>, descuento_base: float}
     */
    public function paraFacturar(Stay $estadia): array
    {
        $cargos = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->orderBy('charge_date')
            ->orderBy('id')
            ->get();

        $lineas = [];
        $noches = [];
        $descuentoBase = 0.0;

        foreach ($cargos as $cargo) {
            if ($cargo->kind === StayCharge::KIND_ADJUSTMENT && (float) $cargo->total < 0) {
                // Al descuento global se le pasa la BASE, no el valor al
                // público: la factura descuenta sobre la base gravable y
                // recalcula el IVA. Pasándole el valor con IVA descontaría de
                // más y el total de la factura no cuadraría con el del folio.
                $descuentoBase += abs((float) $cargo->subtotal);

                continue;
            }

            if ($cargo->kind === StayCharge::KIND_ROOM) {
                // Se agrupan por tarifa e impuesto: si a mitad de estadía se
                // renegoció el precio, son dos líneas distintas y así debe
                // verse.
                $llave = (string) $cargo->unit_price.'|'.(string) $cargo->tax_id;

                if (! isset($noches[$llave])) {
                    $noches[$llave] = [
                        'product_id' => $cargo->product_id,
                        'description' => '',
                        'quantity' => 0,
                        'unit_price' => (float) $cargo->unit_price,
                        'tax_id' => $cargo->tax_id,
                        'tax_rate' => (float) $cargo->tax_rate,
                        'subtotal' => 0.0,
                        'tax_amount' => 0.0,
                        'total' => 0.0,
                        'desde' => $cargo->night_date,
                        'hasta' => $cargo->night_date,
                    ];
                }

                $noches[$llave]['quantity']++;
                $noches[$llave]['subtotal'] += (float) $cargo->subtotal;
                $noches[$llave]['tax_amount'] += (float) $cargo->tax_amount;
                $noches[$llave]['total'] += (float) $cargo->total;

                if ($cargo->night_date && (! $noches[$llave]['desde'] || $cargo->night_date->lt($noches[$llave]['desde']))) {
                    $noches[$llave]['desde'] = $cargo->night_date;
                }

                if ($cargo->night_date && (! $noches[$llave]['hasta'] || $cargo->night_date->gt($noches[$llave]['hasta']))) {
                    $noches[$llave]['hasta'] = $cargo->night_date;
                }

                continue;
            }

            $lineas[] = [
                'product_id' => $cargo->product_id,
                'description' => $cargo->description,
                'quantity' => (float) $cargo->quantity,
                'unit_price' => (float) $cargo->unit_price,
                'tax_id' => $cargo->tax_id,
                'tax_rate' => (float) $cargo->tax_rate,
                'subtotal' => (float) $cargo->subtotal,
                'tax_amount' => (float) $cargo->tax_amount,
                'total' => (float) $cargo->total,
            ];
        }

        // Las noches van primero: es el servicio principal.
        $deAlojamiento = [];

        foreach ($noches as $grupo) {
            $cantidad = (int) $grupo['quantity'];
            $grupo['description'] = sprintf(
                'Alojamiento hab. %s — %d noche%s (%s al %s)',
                $estadia->room?->number ?? '—',
                $cantidad,
                $cantidad === 1 ? '' : 's',
                $grupo['desde']?->format('d/m/Y') ?? '—',
                // La salida es el día siguiente a la última noche dormida.
                $grupo['hasta']?->copy()->addDay()->format('d/m/Y') ?? '—',
            );

            unset($grupo['desde'], $grupo['hasta']);
            $deAlojamiento[] = $grupo;
        }

        return [
            'lineas' => array_merge($deAlojamiento, $lineas),
            'descuento_base' => round($descuentoBase, 2),
        ];
    }

    /**
     * Guarda un renglón descomponiendo el precio al público en base + impuesto.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function guardar(Stay $estadia, array $datos): StayCharge
    {
        $cantidad = (float) $datos['quantity'];
        $precio = (float) $datos['unit_price'];
        $tasa = (float) ($datos['tax_rate'] ?? 0);

        $alPublico = round($cantidad * $precio, 2);
        $base = $tasa > 0 ? round($alPublico / (1 + $tasa / 100), 2) : $alPublico;
        $impuesto = round($alPublico - $base, 2);

        return DB::transaction(function () use ($estadia, $datos, $base, $impuesto, $alPublico) {
            $cargo = StayCharge::create([
                'company_id' => $estadia->company_id,
                'stay_id' => $estadia->id,
                'kind' => $datos['kind'],
                'product_id' => $datos['product_id'],
                'charge_date' => $datos['charge_date'],
                'night_date' => $datos['night_date'],
                'description' => $datos['description'],
                'quantity' => $datos['quantity'],
                'unit_price' => $datos['unit_price'],
                'tax_id' => $datos['tax_id'],
                'tax_rate' => $datos['tax_rate'],
                'tax_amount' => $impuesto,
                'subtotal' => $base,
                'total' => $alPublico,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->recalcular($estadia);

            return $cargo;
        });
    }

    /**
     * El IVA del alojamiento.
     *
     * Null si la estadía está exenta: el hospedaje a un turista extranjero no
     * residente es exento (art. 481 ET). Aplicarle 19% es cobrarle de más y
     * además declararlo mal.
     */
    protected function impuestoDelAlojamiento(Stay $estadia): ?Tax
    {
        if ($estadia->tax_exempt) {
            return null;
        }

        $producto = app(HotelProductProvisioner::class)->ensure($estadia->company);

        return $producto->default_sale_tax_id
            ? Tax::query()->where('company_id', $estadia->company_id)->find($producto->default_sale_tax_id)
            : null;
    }

    protected function exigirAbierta(Stay $estadia): void
    {
        if (! $estadia->estaAbierta()) {
            throw new RuntimeException(
                'El folio '.$estadia->fullNumber().' ya está cerrado. '
                .'Un folio cerrado ya se facturó: lo que falte se cobra en una venta aparte '
                .'o se corrige con una nota crédito.'
            );
        }
    }
}
