<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto del descuento de una línea vino del descuento global de la factura.
 *
 * El descuento global se reparte entre las líneas y se suma a su
 * `discount_amount`. Se hace así —y no con un campo aparte que haya que restar
 * después— porque **doce lugares del sistema calculan la base gravable como
 * `subtotal - discount_amount`**: el asiento contable, los tres constructores
 * de payload de la DIAN, el costeo de compras, las comisiones. Metiendo el
 * global ahí, todos quedan correctos sin tocar ninguno, que es exactamente lo
 * que uno quiere cuando una base mal calculada significa una factura rechazada
 * por la DIAN.
 *
 * El precio de esa decisión es que `discount_amount` deja de ser
 * `subtotal × discount_percentage`. Esta columna es la que permite deshacer la
 * suma: sin ella, recalcular una factura dos veces aplicaría el descuento dos
 * veces.
 */
return new class extends Migration
{
    private const TABLAS = ['sale_invoice_lines', 'purchase_invoice_lines'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->decimal('global_discount_amount', 18, 2)
                    ->default(0)
                    ->after('discount_amount');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('global_discount_amount');
            });
        }
    }
};
