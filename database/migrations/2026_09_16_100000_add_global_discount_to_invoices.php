<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento global (pie de factura) en ventas y compras.
 *
 * Hasta ahora el descuento solo existía por línea. El POS sí tenía uno global
 * —lo reparte entre las líneas para que el IVA quede sobre la base correcta—
 * pero al capturar una factura a mano no había forma de hacer «10 % a toda la
 * factura» sin ir línea por línea.
 *
 * Se guardan tres cosas y no una:
 *
 *   - `type` y `value` son lo que el usuario escribió: «10 %» o «$50.000». Se
 *     conservan para poder reeditar la factura y ver lo que se pactó, no solo
 *     el resultado.
 *   - `amount` es el peso concreto que se descontó. Es lo que se contabiliza,
 *     y no se puede recalcular del porcentaje sin arrastrar el redondeo.
 *
 * El descuento se aplica SOBRE LA BASE, no sobre el total: primero baja la base
 * gravable y después se calcula el IVA. Es lo que exige la DIAN para un
 * descuento no condicionado, y la diferencia no es menor —sobre $1.000.000 con
 * IVA 19 % y 10 % de descuento, hacerlo sobre el total cobraría $19.000 de IVA
 * de más—.
 */
return new class extends Migration
{
    private const TABLAS = ['sale_invoices', 'purchase_invoices'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                // percent | amount
                $table->string('global_discount_type', 10)->default('percent')->after('discount_total');
                // Lo que el usuario escribió: 10 (por ciento) o 50000 (pesos).
                $table->decimal('global_discount_value', 14, 4)->default(0)->after('global_discount_type');
                // Lo que efectivamente se descontó, en pesos.
                $table->decimal('global_discount_amount', 18, 2)->default(0)->after('global_discount_value');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn([
                    'global_discount_type',
                    'global_discount_value',
                    'global_discount_amount',
                ]);
            });
        }
    }
};
