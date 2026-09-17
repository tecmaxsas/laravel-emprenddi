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
 */
class StockPreview
{
    /**
     * El renglón de stock para una línea de venta.
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
                '<span class="sp-nota">Este producto no controla inventario.</span>'
            );
        }

        $inventario = app(InventoryEngine::class);

        $sedes = Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name']);

        $partes = [];

        foreach ($sedes as $sede) {
            $actual = $inventario->currentStock($producto->id, $sede->id);

            // Solo se descuenta de la sede que vende: las demás no se tocan.
            $esLaQueVende = $locationId && (int) $sede->id === (int) $locationId;
            $despues = $esLaQueVende ? $actual - $cantidad : $actual;

            // Una sede en cero que no participa en esta venta es ruido: lo que
            // importa es dónde sí hay.
            if (! $esLaQueVende && abs($actual) < 0.001) {
                continue;
            }

            $partes[] = self::renglon($sede->name, $actual, $despues, $esLaQueVende);
        }

        if ($partes === []) {
            return new HtmlString('<span class="sp-agotado">Sin existencias en ninguna sede.</span>');
        }

        return new HtmlString(
            '<div class="sp-wrap">'.implode('', $partes).'</div>'.self::estilos()
        );
    }

    private static function renglon(string $sede, float $actual, float $despues, bool $esLaQueVende): string
    {
        $clase = match (true) {
            $despues < -0.001 => 'sp-negativo',
            $despues < 0.001 => 'sp-cero',
            default => 'sp-ok',
        };

        $flecha = $esLaQueVende && abs($actual - $despues) > 0.001
            ? ' <span class="sp-flecha">→</span> <strong>'.self::numero($despues).'</strong>'
            : '';

        return sprintf(
            '<span class="sp-item %s"%s><span class="sp-sede">%s</span> %s%s</span>',
            $clase,
            $esLaQueVende ? ' data-vende="1"' : '',
            e($sede),
            self::numero($actual),
            $flecha,
        );
    }

    private static function numero(float $n): string
    {
        // Las unidades enteras se leen mejor sin decimales, pero un producto
        // que se vende por kilo o metro los necesita.
        return abs($n - round($n)) < 0.001
            ? number_format($n, 0, ',', '.')
            : number_format($n, 2, ',', '.');
    }

    private static function estilos(): string
    {
        return <<<'HTML'
            <style>
                .sp-wrap { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
                .sp-item { display:inline-flex; align-items:center; gap:5px; padding:2px 8px;
                           border-radius:999px; font-size:11.5px; font-variant-numeric:tabular-nums;
                           background:#f1f5f9; color:#334155; border:1px solid transparent; }
                .sp-item[data-vende="1"] { border-color:#94a3b8; font-weight:600; }
                .sp-sede { opacity:.7; }
                .sp-flecha { opacity:.5; }
                .sp-ok { background:#dcfce7; color:#166534; }
                .sp-cero { background:#fef9c3; color:#854d0e; }
                .sp-negativo { background:#fee2e2; color:#991b1b; }
                .sp-nota, .sp-agotado { font-size:11.5px; color:#64748b; }
                .sp-agotado { color:#b91c1c; font-weight:600; }
                .dark .sp-item { background:#334155; color:#e2e8f0; }
                .dark .sp-ok { background:#14532d; color:#bbf7d0; }
                .dark .sp-cero { background:#422006; color:#fde68a; }
                .dark .sp-negativo { background:#450a0a; color:#fecaca; }
                .dark .sp-nota { color:#94a3b8; }
                .dark .sp-agotado { color:#fca5a5; }
            </style>
        HTML;
    }
}
