<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use RuntimeException;

/**
 * Sacar del almacén lo que se consumió, sea un producto o un combo.
 *
 * Existe porque hay dos momentos en que la mercancía sale sin que sean el
 * mismo proceso:
 *
 *   - Al **facturar** una venta.
 *   - Al cerrar una cuenta de restaurante **sin facturar** —«casa invita»—,
 *     donde no hay factura ni plata pero la comida igual salió de la cocina.
 *
 * Sin esto, la regla de cómo se descarga un combo —que no sale el combo sino
 * sus piezas— habría que escribirla en los dos sitios, y el día que cambie se
 * cambiará en uno solo. Un inventario que se descarga distinto según por dónde
 * salió la mercancía no se puede cuadrar contra nada.
 */
class DescargaDeVenta
{
    public function __construct(
        private readonly InventoryEngine $inventory,
    ) {}

    /**
     * Descarga un producto, expandiendo el combo si lo es.
     *
     * @param  array<string, mixed>  $contexto  lo que identifica el movimiento
     *                                          (type, date, reference_*, description…)
     * @return array{costo: float, movimiento: ?InventoryMovement}
     */
    public function descargar(
        Product $producto,
        Location $sede,
        float $cantidad,
        array $contexto,
        bool $permitirNegativo = false,
    ): array {
        $cantidad = abs($cantidad);

        if ($cantidad <= 0) {
            return ['costo' => 0.0, 'movimiento' => null];
        }

        if ($producto->isKit()) {
            return $this->descargarCombo($producto, $sede, $cantidad, $contexto, $permitirNegativo);
        }

        // Un servicio o un producto sin control de inventario no sale de
        // ningun lado: no es un error, simplemente no hay nada que mover.
        if (! $producto->track_inventory) {
            return ['costo' => 0.0, 'movimiento' => null];
        }

        $movimiento = $this->inventory->addMovement($producto, $sede, array_merge($contexto, [
            'quantity' => $cantidad,
            'allow_negative' => $permitirNegativo,
        ]));

        return [
            'costo' => abs((float) $movimiento->quantity) * (float) $movimiento->unit_cost,
            'movimiento' => $movimiento,
        ];
    }

    /**
     * Un combo no sale del almacén: salen sus piezas.
     *
     * Cada componente por su cantidad en la receta multiplicada por cuántos
     * combos se consumieron. El costo es la suma de lo que costaron esas
     * piezas: el combo nunca se compró, se armó.
     *
     * @param  array<string, mixed>  $contexto
     * @return array{costo: float, movimiento: ?InventoryMovement}
     */
    private function descargarCombo(
        Product $combo,
        Location $sede,
        float $cantidad,
        array $contexto,
        bool $permitirNegativo,
    ): array {
        $componentes = $combo->components()->with('component')->get();

        if ($componentes->isEmpty()) {
            // Dejarlo pasar seria una salida sin costo y sin movimiento:
            // margen del 100% y almacen intacto, el error que nadie revisa
            // hasta que cuadra el inventario a fin de mes.
            throw new RuntimeException(sprintf(
                'El combo «%s» no tiene componentes definidos. Configúralos en la ficha '
                .'del producto: sin ellos no se descargaría inventario ni habría costo.',
                $combo->name,
            ));
        }

        $costo = 0.0;
        $primerMovimiento = null;

        foreach ($componentes as $pieza) {
            $producto = $pieza->component;

            if (! $producto || ! $producto->track_inventory) {
                continue;
            }

            $aDescargar = $cantidad * (float) $pieza->quantity;

            if ($aDescargar <= 0) {
                continue;
            }

            $movimiento = $this->inventory->addMovement($producto, $sede, array_merge($contexto, [
                'quantity' => $aDescargar,
                'allow_negative' => $permitirNegativo,
                'description' => ($contexto['description'] ?? '').' — combo '.$combo->name,
            ]));

            $primerMovimiento ??= $movimiento;
            $costo += abs((float) $movimiento->quantity) * (float) $movimiento->unit_cost;
        }

        return ['costo' => $costo, 'movimiento' => $primerMovimiento];
    }
}
