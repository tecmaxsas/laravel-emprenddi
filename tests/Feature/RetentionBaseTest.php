<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use ReflectionMethod;
use Tests\TestCase;

/**
 * La base gravable de una retención.
 *
 * El campo se digitaba a mano y la ayuda decía «típicamente subtotal menos
 * descuento» para todas. Eso es cierto para retefuente y ReteICA, y falso para
 * ReteIVA: esa se calcula sobre el IVA, no sobre el subtotal. Con IVA del 19 %,
 * seguir esa ayuda multiplica la retención por más de cinco.
 *
 * Ahora la base se sugiere según el tipo. Sigue siendo editable —hay bases
 * parciales, y en compras hay bases mínimas en UVT— pero el valor de partida ya
 * no es el equivocado.
 *
 * No toca la base de datos: solo verifica el cálculo.
 */
class RetentionBaseTest extends TestCase
{
    /** @var list<array<string, float>> */
    private array $lineas = [
        // 1.000.000 con 10 % de descuento → gravable 900.000, IVA 171.000
        ['subtotal' => 1000000, 'discount_amount' => 100000, 'tax_amount' => 171000],
        // 500.000 sin descuento ni IVA (excluido)
        ['subtotal' => 500000, 'discount_amount' => 0, 'tax_amount' => 0],
    ];

    /** Retefuente: subtotal menos descuentos, sin impuestos. */
    public function test_la_retencion_en_la_fuente_va_sobre_la_base_sin_impuestos(): void
    {
        foreach ($this->resources() as $nombre => $calcular) {
            $this->assertEqualsWithDelta(
                1400000, $calcular('income_withholding', $this->lineas), 0.01,
                "En {$nombre} la base de retefuente debe excluir el IVA.",
            );
        }
    }

    /** ReteICA se comporta igual que retefuente. */
    public function test_reteica_usa_la_misma_base_que_retefuente(): void
    {
        foreach ($this->resources() as $nombre => $calcular) {
            $this->assertEqualsWithDelta(
                1400000, $calcular('ica_withholding', $this->lineas), 0.01,
                "En {$nombre} la base de ReteICA debe excluir el IVA.",
            );
        }
    }

    /**
     * ReteIVA es la excepción, y es la que costaba plata: su base es el IVA
     * facturado, no el subtotal.
     */
    public function test_reteiva_va_sobre_el_iva_y_no_sobre_el_subtotal(): void
    {
        foreach ($this->resources() as $nombre => $calcular) {
            $base = $calcular('vat_withholding', $this->lineas);

            $this->assertEqualsWithDelta(171000, $base, 0.01,
                "En {$nombre} la base de ReteIVA debe ser el IVA de la factura.");
            $this->assertNotEqualsWithDelta(1400000, $base, 0.01,
                'Usar el subtotal como base de ReteIVA infla la retención varias veces.');
        }
    }

    /** Con una factura vacía la sugerencia es cero, no un error. */
    public function test_una_factura_sin_lineas_sugiere_cero(): void
    {
        foreach ($this->resources() as $nombre => $calcular) {
            $this->assertSame(0.0, $calcular('income_withholding', []), $nombre);
            $this->assertSame(0.0, $calcular('vat_withholding', null), $nombre);
        }
    }

    /**
     * Retenido = base × tarifa. Sobre 1.400.000 al 2,5 % son 35.000; si la base
     * hubiera arrastrado el IVA serían 39.275.
     */
    public function test_el_monto_retenido_sale_de_la_base_por_la_tarifa(): void
    {
        foreach ($this->resources() as $nombre => $calcular) {
            $base = $calcular('income_withholding', $this->lineas);

            $this->assertEqualsWithDelta(35000, round($base * 2.5 / 100, 2), 0.01, $nombre);
        }
    }

    /**
     * @return array<string, callable(?string, ?array): float>
     */
    private function resources(): array
    {
        $resolver = function (string $clase): callable {
            $metodo = new ReflectionMethod($clase, 'retentionBaseFor');
            $metodo->setAccessible(true);

            return fn (?string $tipo, ?array $lineas) => $metodo->invoke(null, $tipo, $lineas);
        };

        return [
            'facturas de venta' => $resolver(SaleInvoiceResource::class),
            'facturas de compra' => $resolver(PurchaseInvoiceResource::class),
        ];
    }
}
