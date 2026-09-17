<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\HtmlString;

/**
 * Cuánto hay de un producto y cuánto va a quedar al facturarlo.
 *
 * Quien está armando una factura necesita las dos cifras a la vez y ninguna le
 * sirve sola: saber que hay 12 no dice nada si la línea lleva 15, y saber que
 * quedarán −3 no dice de dónde salió. Verlas juntas, mientras se digita, evita
 * el viaje de ida y vuelta a la pantalla de inventario —y la venta que se
 * descubre imposible cuando el cliente ya está esperando—.
 *
 * Muestra **todas las sedes**, no solo la que vende: si en la que vende no
 * alcanza, lo primero que uno quiere saber es si hay en otra.
 *
 * **El nombre y el número van en renglones distintos** a propósito. Las sedes se
 * llaman cosas como «MUNDO ELECTRONICO CR 10 11 45», y puestos en la misma línea
 * el stock se pierde entre los números de la dirección: nadie distingue el «10»
 * de la carrera del «10» de las unidades disponibles.
 */
class StockPreview
{
    /**
     * Abreviaturas de unidad para mostrar junto a la cantidad.
     *
     * `Product::COMMON_UNITS` trae el nombre largo, que aquí estorba: «10
     * Unidad» se lee raro y ocupa el doble. Lo que la gente escribe en un
     * inventario es «10 und».
     */
    private const ABREVIATURAS = [
        'unit' => 'und',
        'kg' => 'kg',
        'g' => 'g',
        'l' => 'L',
        'ml' => 'ml',
        'm' => 'm',
        'cm' => 'cm',
        'm2' => 'm²',
        'm3' => 'm³',
        'box' => 'cajas',
        'pack' => 'paq',
        'hour' => 'h',
        'day' => 'días',
        'service' => 'serv',
    ];

    /**
     * El bloque de stock para una línea de venta.
     *
     * @param  int|null  $productId  El producto de la línea.
     * @param  int|null  $locationId  La sede desde la que se vende.
     * @param  float  $cantidad  Lo que lleva la línea.
     */
    public static function paraLinea(?int $productId, ?int $locationId, float $cantidad = 0): ?HtmlString
    {
        if (! $productId) {
            return null;
        }

        $producto = Product::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($productId);

        if (! $producto) {
            return null;
        }

        if (! $producto->track_inventory) {
            return new HtmlString(
                '<div class="sp-nota">Este producto no controla inventario.</div>'.self::estilos()
            );
        }

        $unidad = self::ABREVIATURAS[$producto->unit_of_measure]
            ?? mb_strtolower(Product::COMMON_UNITS[$producto->unit_of_measure] ?? 'und');

        $inventario = app(InventoryEngine::class);

        $sedes = Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name']);

        $tarjetas = [];

        foreach ($sedes as $sede) {
            $actual = $inventario->currentStock($producto->id, $sede->id);
            $esLaQueVende = $locationId && (int) $sede->id === (int) $locationId;

            // Una sede en cero que no participa en esta venta es ruido: lo que
            // importa es dónde sí hay.
            if (! $esLaQueVende && abs($actual) < 0.001) {
                continue;
            }

            $tarjetas[] = self::tarjeta(
                $sede->name,
                $actual,
                $esLaQueVende ? $actual - $cantidad : $actual,
                $esLaQueVende,
                $unidad,
            );
        }

        if ($tarjetas === []) {
            return new HtmlString(
                '<div class="sp-agotado">Sin existencias en ninguna sede.</div>'.self::estilos()
            );
        }

        return new HtmlString(
            '<div class="sp-bloque">'
            .'<div class="sp-titulo">Existencias</div>'
            .'<div class="sp-grid">'.implode('', $tarjetas).'</div>'
            .'</div>'
            .self::estilos()
        );
    }

    private static function tarjeta(
        string $sede,
        float $actual,
        float $despues,
        bool $esLaQueVende,
        string $unidad,
    ): string {
        $clase = match (true) {
            $despues < -0.001 => 'sp-negativo',
            $despues < 0.001 => 'sp-cero',
            default => 'sp-ok',
        };

        $cambia = $esLaQueVende && abs($actual - $despues) > 0.001;

        // Cuando la venta mueve el saldo, el número de antes pasa a segundo
        // plano: lo que importa es con cuánto se queda.
        $cifras = $cambia
            ? sprintf(
                '<span class="sp-antes">%s</span>'
                .'<span class="sp-flecha">→</span>'
                .'<span class="sp-despues">%s <span class="sp-unidad">%s</span></span>',
                self::numero($actual),
                self::numero($despues),
                e($unidad),
            )
            : sprintf(
                '<span class="sp-despues">%s <span class="sp-unidad">%s</span></span>',
                self::numero($actual),
                e($unidad),
            );

        return sprintf(
            '<div class="sp-tarjeta %s%s">'
            .'<div class="sp-sede" title="%s">%s%s</div>'
            .'<div class="sp-cifras">%s</div>'
            .'</div>',
            $clase,
            $esLaQueVende ? ' sp-vende' : '',
            e($sede),
            e($sede),
            $esLaQueVende ? ' <span class="sp-aqui">vende aquí</span>' : '',
            $cifras,
        );
    }

    private static function numero(float $n): string
    {
        // Las unidades enteras se leen mejor sin decimales, pero un producto que
        // se vende por kilo o metro los necesita.
        return abs($n - round($n)) < 0.001
            ? number_format($n, 0, ',', '.')
            : number_format($n, 2, ',', '.');
    }

    private static function estilos(): string
    {
        return <<<'HTML'
            <style>
                .sp-bloque { margin-top: 2px; }

                .sp-titulo {
                    font-size: 10.5px; font-weight: 700; letter-spacing: .06em;
                    text-transform: uppercase; color: #94a3b8; margin-bottom: 5px;
                }

                .sp-grid { display: flex; flex-wrap: wrap; gap: 8px; }

                .sp-tarjeta {
                    min-width: 150px; padding: 7px 11px; border-radius: 9px;
                    border: 1px solid #e2e8f0; background: #f8fafc; line-height: 1.35;
                }

                /* El nombre arriba y el numero abajo: las sedes se llaman «CR 10
                   11 45» y en una sola linea el stock se pierde entre los
                   numeros de la direccion. */
                .sp-sede {
                    font-size: 10.5px; color: #64748b; font-weight: 600;
                    text-transform: uppercase; letter-spacing: .03em;
                    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                    max-width: 230px;
                }

                .sp-aqui {
                    font-size: 9.5px; font-weight: 700; letter-spacing: .04em;
                    padding: 1px 5px; border-radius: 999px; margin-left: 4px;
                    background: #e0e7ff; color: #3730a3; text-transform: none;
                }

                .sp-cifras {
                    display: flex; align-items: baseline; gap: 6px;
                    margin-top: 2px; font-variant-numeric: tabular-nums;
                }

                .sp-antes { font-size: 13px; color: #94a3b8; text-decoration: line-through; }
                .sp-flecha { font-size: 12px; color: #94a3b8; }
                .sp-despues { font-size: 17px; font-weight: 800; }
                .sp-unidad { font-size: 11px; font-weight: 600; opacity: .65; margin-left: 1px; }

                .sp-ok { border-color: #bbf7d0; background: #f0fdf4; }
                .sp-ok .sp-despues { color: #15803d; }

                .sp-cero { border-color: #fde68a; background: #fffbeb; }
                .sp-cero .sp-despues { color: #a16207; }

                .sp-negativo { border-color: #fecaca; background: #fef2f2; }
                .sp-negativo .sp-despues { color: #b91c1c; }

                .sp-vende { box-shadow: inset 0 0 0 1px rgba(100,116,139,.35); }

                .sp-nota, .sp-agotado { font-size: 12px; color: #64748b; }
                .sp-agotado { color: #b91c1c; font-weight: 700; }

                .dark .sp-titulo { color: #64748b; }
                .dark .sp-tarjeta { border-color: #334155; background: #1e293b; }
                .dark .sp-sede { color: #94a3b8; }
                .dark .sp-aqui { background: #312e81; color: #c7d2fe; }
                .dark .sp-antes, .dark .sp-flecha { color: #64748b; }
                .dark .sp-ok { border-color: #166534; background: #052e16; }
                .dark .sp-ok .sp-despues { color: #86efac; }
                .dark .sp-cero { border-color: #854d0e; background: #2a1c02; }
                .dark .sp-cero .sp-despues { color: #fde68a; }
                .dark .sp-negativo { border-color: #991b1b; background: #2c0a0a; }
                .dark .sp-negativo .sp-despues { color: #fca5a5; }
                .dark .sp-nota { color: #94a3b8; }
                .dark .sp-agotado { color: #fca5a5; }
            </style>
        HTML;
    }
}
