<?php

namespace App\Filament\Concerns;

use App\Services\Invoicing\GlobalDiscount;
use App\Support\DiscountSettings;
use Filament\Forms;

/**
 * El bloque de descuento global del formulario, y sus totales en vivo.
 *
 * Lo comparten las facturas de venta y las de compra: el cálculo es el mismo y
 * lo único que cambia es qué ajuste lo habilita.
 *
 * Ojo con una sutileza: quien reparte el descuento de verdad es el motor, al
 * guardar. Lo de aquí es solo la vista previa, y tiene que dar el mismo número
 * —si el formulario muestra un total y la factura guardada tiene otro, nadie
 * vuelve a confiar en la pantalla—.
 */
trait PreviewsGlobalDiscount
{
    /** ¿Esta empresa tiene habilitado el descuento global en este documento? */
    abstract protected static function allowsGlobalDiscount(): bool;

    /** @return list<Forms\Components\Component> */
    protected static function globalDiscountSection(): array
    {
        if (! static::allowsGlobalDiscount()) {
            return [];
        }

        return [
            Forms\Components\Section::make('Descuento global')
                ->description('Un descuento a toda la factura, además de los de cada línea. '
                    .'Se aplica sobre la base gravable: el IVA se calcula sobre el valor ya descontado.')
                ->collapsed(fn ($record) => ! $record || (float) $record->global_discount_value <= 0)
                ->collapsible()
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Forms\Components\Select::make('global_discount_type')
                        ->label('Tipo')
                        ->options(GlobalDiscount::TYPES)
                        ->default(GlobalDiscount::TYPE_PERCENT)
                        ->native(false)
                        ->live(),

                    Forms\Components\TextInput::make('global_discount_value')
                        ->label('Descuento')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->prefix(fn (Forms\Get $get) => $get('global_discount_type') === GlobalDiscount::TYPE_AMOUNT ? '$' : null)
                        ->suffix(fn (Forms\Get $get) => $get('global_discount_type') === GlobalDiscount::TYPE_AMOUNT ? null : '%')
                        ->helperText('Déjalo en cero si no aplica.'),

                    Forms\Components\Placeholder::make('global_discount_preview')
                        ->label('Se descontará')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            static::globalDiscountAmount($get), 2)),
                ]),
        ];
    }

    /**
     * La base gravable de las líneas antes del descuento global: subtotal
     * menos los descuentos propios de cada línea.
     */
    protected static function linesBase(Forms\Get $get): float
    {
        return collect($get('lines') ?? [])->sum(
            fn ($l) => (float) ($l['subtotal'] ?? 0) - (float) ($l['discount_amount'] ?? 0)
        );
    }

    /** Cuánto descuenta el global, dada la base de las líneas. */
    protected static function globalDiscountAmount(Forms\Get $get): float
    {
        if (! static::allowsGlobalDiscount()) {
            return 0.0;
        }

        $valor = max(0, (float) ($get('global_discount_value') ?? 0));
        $base = static::linesBase($get);

        if ($valor <= 0 || $base <= 0) {
            return 0.0;
        }

        return ($get('global_discount_type') ?? GlobalDiscount::TYPE_PERCENT) === GlobalDiscount::TYPE_AMOUNT
            ? round(min($valor, $base), 2)
            : round($base * min(100, $valor) / 100, 2);
    }

    /**
     * Los totales del pie, ya con el descuento global repartido.
     *
     * El IVA se recalcula proporcionalmente porque cada línea puede tener una
     * tarifa distinta: aplicarle el porcentaje al IVA total daría lo mismo solo
     * si todas las líneas tuvieran la misma.
     *
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    protected static function previewTotals(Forms\Get $get): array
    {
        $lineas = collect($get('lines') ?? []);
        $subtotal = (float) $lineas->sum(fn ($l) => (float) ($l['subtotal'] ?? 0));
        $descuentoLineas = (float) $lineas->sum(fn ($l) => (float) ($l['discount_amount'] ?? 0));

        $base = max(0, $subtotal - $descuentoLineas);
        $global = static::globalDiscountAmount($get);
        $factor = $base > 0 ? ($base - $global) / $base : 1.0;

        $impuesto = (float) $lineas->sum(function ($l) use ($factor) {
            $baseLinea = ((float) ($l['subtotal'] ?? 0) - (float) ($l['discount_amount'] ?? 0)) * $factor;

            return round($baseLinea * (float) ($l['tax_rate'] ?? 0) / 100, 2);
        });

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($descuentoLineas + $global, 2),
            'tax' => round($impuesto, 2),
            'total' => round($base - $global + $impuesto, 2),
        ];
    }

    /** Atajo para no repetir la resolución del ajuste en cada resource. */
    protected static function discountsEnabledFor(string $documento): bool
    {
        return $documento === 'sales'
            ? DiscountSettings::allowsOnSaleInvoices()
            : DiscountSettings::allowsOnPurchaseInvoices();
    }
}
