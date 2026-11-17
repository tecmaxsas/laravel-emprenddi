<?php

namespace App\Services\Billiards;

use App\Models\Billiards\BilliardCharge;
use App\Models\Billiards\BilliardSession;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La cuenta de la mesa: lo que deben, renglon por renglon.
 *
 * Es el unico sitio que escribe los totales de una sesion. Estan en columnas
 * y no se suman al leer porque el mapa del salon muestra el saldo de cada
 * mesa ocupada: sumar renglones en cada pintada seria una consulta por mesa,
 * y el mapa se repinta solo cada pocos segundos.
 *
 * Esa decision tiene un costo —un total guardado puede quedar viejo— asi que
 * no hay forma de tocar un renglon sin pasar por aqui, y todo lo que lo toca
 * recalcula al salir, en la misma transaccion.
 *
 * LOS PRECIOS SON AL PUBLICO (IVA incluido), igual que el folio del hotel y
 * los extras del parqueadero. En un billar eso no es un detalle tecnico: el
 * aviso de la pared dice «hora $10.000» y eso es lo que el cliente espera
 * pagar. Guardar la base gravable haria que la cuenta no coincidiera con el
 * aviso. La base se descompone al guardar cada renglon, asi que la factura
 * copia base e impuesto tal cual y no hay dos calculos de IVA que puedan
 * discrepar en centavos.
 */
class BilliardAccount
{
    /**
     * Carga un consumo a la mesa: la cerveza, los cigarrillos, el alquiler
     * del taco.
     *
     * @param  array{product_id?: int|null, description?: string, quantity?: float,
     *               unit_price?: float, tax_id?: int|null, kind?: string}  $datos
     */
    public function agregarConsumo(BilliardSession $sesion, array $datos): BilliardCharge
    {
        $this->exigirAbierta($sesion);

        $producto = isset($datos['product_id']) && $datos['product_id']
            ? Product::query()->where('company_id', $sesion->company_id)->find($datos['product_id'])
            : null;

        $descripcion = trim((string) ($datos['description'] ?? $producto?->name ?? ''));

        if ($descripcion === '') {
            throw new RuntimeException('El cargo necesita una descripción para que el cliente sepa qué está pagando.');
        }

        $cantidad = (float) ($datos['quantity'] ?? 1);

        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad del cargo tiene que ser mayor que cero.');
        }

        $precio = (float) ($datos['unit_price'] ?? $producto?->default_sale_price ?? 0);

        if ($precio <= 0) {
            throw new RuntimeException('El cargo necesita un valor. Un renglón en cero no se cobra y confunde la cuenta.');
        }

        $impuesto = $this->impuesto($sesion, $datos['tax_id'] ?? $producto?->default_sale_tax_id);

        $kind = in_array($datos['kind'] ?? '', [BilliardCharge::KIND_PRODUCT, BilliardCharge::KIND_SERVICE], true)
            ? $datos['kind']
            : ($producto && $producto->type !== 'service'
                ? BilliardCharge::KIND_PRODUCT
                : BilliardCharge::KIND_SERVICE);

        return $this->guardar($sesion, [
            'kind' => $kind,
            'product_id' => $producto?->id,
            'description' => $descripcion,
            'quantity' => $cantidad,
            'unit_price' => $precio,
            'tax_id' => $impuesto?->id,
            'tax_rate' => $impuesto ? (float) $impuesto->rate : 0.0,
        ]);
    }

    /**
     * Escribe en la cuenta el tiempo de un tramo jugado.
     *
     * Lo llama el motor de sesiones al cerrar y al trasladar de mesa: cada
     * tramo queda con su mesa y su tarifa, que es como se le explica al
     * cliente que se cambio de la 3 a la 7 en mitad de la noche.
     *
     * @param  array{amount: float, minutes: int, charge_minutes: int}  $liquidacion
     */
    public function agregarTiempo(
        BilliardSession $sesion,
        array $liquidacion,
        string $descripcion,
    ): ?BilliardCharge {
        $monto = round((float) ($liquidacion['amount'] ?? 0), 2);

        // Una cortesía completa, o un traslado a los dos minutos, liquidan en
        // cero. Un renglón de $0 en la cuenta no dice nada y hace creer que
        // algo se cobró mal.
        if ($monto <= 0) {
            return null;
        }

        $producto = app(BilliardProductProvisioner::class)->ensure($sesion->company);
        $impuesto = $this->impuesto($sesion, $producto->default_sale_tax_id);

        return $this->guardar($sesion, [
            'kind' => BilliardCharge::KIND_TIME,
            'product_id' => $producto->id,
            'description' => $descripcion,
            'quantity' => 1,
            'unit_price' => $monto,
            'tax_id' => $impuesto?->id,
            'tax_rate' => $impuesto ? (float) $impuesto->rate : 0.0,
        ]);
    }

    /**
     * Un ajuste a la baja: la cortesia de la casa, el descuento del torneo,
     * la media hora que no se cobra porque el paño estaba roto.
     *
     * Entra como renglon negativo y al facturar se convierte en el descuento
     * global de la factura, que la DIAN si acepta —baja la base gravable y el
     * IVA se recalcula sobre lo descontado—. Una linea de factura con valor
     * negativo, en cambio, la rechaza.
     */
    public function agregarAjuste(
        BilliardSession $sesion,
        string $concepto,
        float $monto,
        ?int $taxId = null,
    ): BilliardCharge {
        $this->exigirAbierta($sesion);

        $concepto = trim($concepto);

        if ($concepto === '') {
            throw new RuntimeException('Un ajuste a la cuenta tiene que decir por qué. Es lo que se le explica al cliente.');
        }

        $monto = abs($monto);

        if ($monto <= 0) {
            throw new RuntimeException('El ajuste tiene que tener un valor.');
        }

        $impuesto = $this->impuesto($sesion, $taxId)
            ?: $this->impuestoDelTiempo($sesion);

        return $this->guardar($sesion, [
            'kind' => BilliardCharge::KIND_ADJUSTMENT,
            'product_id' => null,
            'description' => $concepto,
            'quantity' => 1,
            'unit_price' => -$monto,
            'tax_id' => $impuesto?->id,
            'tax_rate' => $impuesto ? (float) $impuesto->rate : 0.0,
        ]);
    }

    /**
     * Quita un renglon. Solo mientras la cuenta este abierta, y nunca el
     * tiempo: el tiempo lo decide el reloj, no la mano del cajero. Para
     * perdonarlo esta el ajuste, que deja constancia de quien regalo esa
     * plata y por que.
     */
    public function quitar(BilliardCharge $cargo): void
    {
        $sesion = $cargo->session;

        if (! $sesion) {
            throw new RuntimeException('El cargo no pertenece a ninguna cuenta.');
        }

        $this->exigirAbierta($sesion);

        if ($cargo->loPoneElSistema()) {
            throw new RuntimeException(
                'El tiempo de mesa no se quita a mano. Si hay que perdonarlo, usa un ajuste: '
                .'así queda registrado quién lo autorizó.'
            );
        }

        DB::transaction(function () use ($cargo, $sesion) {
            $cargo->delete();
            $this->recalcular($sesion);
        });
    }

    /**
     * Vuelve a sumar la cuenta y escribe los totales.
     *
     * Los ajustes negativos no entran al total como un renglon mas: se suman
     * aparte en `discount_total` porque al facturar se convierten en el
     * descuento global de la factura.
     */
    public function recalcular(BilliardSession $sesion): BilliardSession
    {
        $cargos = BilliardCharge::query()
            ->where('session_id', $sesion->id)
            ->get(['kind', 'subtotal', 'tax_amount', 'total']);

        $tiempo = 0.0;
        $consumos = 0.0;
        $descuento = 0.0;
        $impuestos = 0.0;

        foreach ($cargos as $cargo) {
            $total = (float) $cargo->total;
            $impuestos += (float) $cargo->tax_amount;

            if ($cargo->kind === BilliardCharge::KIND_TIME) {
                $tiempo += $total;

                continue;
            }

            if ($cargo->kind === BilliardCharge::KIND_ADJUSTMENT) {
                $total < 0 ? $descuento += abs($total) : $consumos += $total;

                continue;
            }

            $consumos += $total;
        }

        $sesion->forceFill([
            'time_total' => round($tiempo, 2),
            'consumption_total' => round($consumos, 2),
            'discount_total' => round($descuento, 2),
            'tax_total' => round($impuestos, 2),
            'total' => round($tiempo + $consumos - $descuento, 2),
        ])->save();

        return $sesion;
    }

    /**
     * Los renglones que van a la factura.
     *
     * A diferencia del folio del hotel, aqui no se agrupa nada: una cuenta de
     * billar tiene uno o dos renglones de tiempo y un puñado de consumos, y
     * el cliente quiere verlos tal como los pidio.
     *
     * @return array{lineas: list<array<string, mixed>>, descuento_base: float}
     */
    public function paraFacturar(BilliardSession $sesion): array
    {
        $cargos = BilliardCharge::query()
            ->where('session_id', $sesion->id)
            ->orderByRaw("CASE WHEN kind = ? THEN 0 ELSE 1 END", [BilliardCharge::KIND_TIME])
            ->orderBy('id')
            ->get();

        $lineas = [];
        $descuentoBase = 0.0;

        foreach ($cargos as $cargo) {
            if ($cargo->kind === BilliardCharge::KIND_ADJUSTMENT && (float) $cargo->total < 0) {
                // Al descuento global se le pasa la BASE, no el valor al
                // publico: la factura descuenta sobre la base gravable y
                // recalcula el IVA. Pasandole el valor con IVA descontaria de
                // mas y el total de la factura no cuadraria con el de la cuenta.
                $descuentoBase += abs((float) $cargo->subtotal);

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

        return [
            'lineas' => $lineas,
            'descuento_base' => round($descuentoBase, 2),
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $datos */
    protected function guardar(BilliardSession $sesion, array $datos): BilliardCharge
    {
        $cantidad = (float) $datos['quantity'];
        $precio = (float) $datos['unit_price'];
        $tasa = (float) ($datos['tax_rate'] ?? 0);

        $alPublico = round($cantidad * $precio, 2);
        $base = $tasa > 0 ? round($alPublico / (1 + $tasa / 100), 2) : $alPublico;
        $impuesto = round($alPublico - $base, 2);

        return DB::transaction(function () use ($sesion, $datos, $base, $impuesto, $alPublico) {
            $cargo = BilliardCharge::create([
                'company_id' => $sesion->company_id,
                'session_id' => $sesion->id,
                'kind' => $datos['kind'],
                'product_id' => $datos['product_id'],
                'charge_date' => now()->toDateString(),
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

            $this->recalcular($sesion);

            return $cargo;
        });
    }

    protected function impuesto(BilliardSession $sesion, ?int $taxId): ?Tax
    {
        if (! $taxId) {
            return null;
        }

        return Tax::query()->where('company_id', $sesion->company_id)->find($taxId);
    }

    protected function impuestoDelTiempo(BilliardSession $sesion): ?Tax
    {
        $producto = app(BilliardProductProvisioner::class)->ensure($sesion->company);

        return $this->impuesto($sesion, $producto->default_sale_tax_id);
    }

    protected function exigirAbierta(BilliardSession $sesion): void
    {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException(
                'La cuenta de esta mesa ya está cerrada. Para corregirla hay que ir a la factura.'
            );
        }
    }
}
