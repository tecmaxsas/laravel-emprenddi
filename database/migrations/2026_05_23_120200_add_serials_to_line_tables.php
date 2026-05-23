<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna `serials` (json) en las tablas de líneas. Almacena el array de
 * strings capturado por el TagsInput mientras el documento está en draft.
 * Al postear, el Engine lee este array, crea los registros en
 * product_serials y deja la columna intacta para auditoría.
 *
 * No es la fuente de verdad: la tabla product_serials lo es.
 * Esta columna sólo persiste lo que el usuario digitó/pegó en el form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->json('serials')->nullable()->after('inventory_movement_id');
        });

        Schema::table('sale_invoice_lines', function (Blueprint $table) {
            $table->json('serials')->nullable()->after('inventory_movement_id');
        });

        Schema::table('inventory_adjustment_lines', function (Blueprint $table) {
            $table->json('serials')->nullable()->after('inventory_movement_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('serials');
        });
        Schema::table('sale_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('serials');
        });
        Schema::table('inventory_adjustment_lines', function (Blueprint $table) {
            $table->dropColumn('serials');
        });
    }
};
