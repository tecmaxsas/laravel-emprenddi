<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue resoluciones POS de resoluciones de facturación electrónica.
 *
 *  - dian_resolutions.kind: 'electronic' | 'pos'
 *      electronic → se registra y transmite a la DIAN.
 *      pos        → numeración local, NO transmite a DIAN.
 *
 *  - sale_invoices.invoice_kind: 'electronic' | 'pos' (denormalizado para
 *      reportes y para saber si una factura debe ir a DIAN).
 *
 * Defaults: las resoluciones y facturas existentes quedan 'electronic'
 * (comportamiento sin cambios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_resolutions', function (Blueprint $table) {
            $table->string('kind', 20)->default('electronic')->after('company_id');
        });

        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->string('invoice_kind', 20)->nullable()->after('number');
        });

        // Backfill: facturas existentes con resolución → toman el kind de su
        // resolución; sin resolución → electronic por compatibilidad.
        DB::statement("
            update sale_invoices si
            set invoice_kind = coalesce(
                (select r.kind from dian_resolutions r where r.id = si.dian_resolution_id),
                'electronic'
            )
            where si.invoice_kind is null
        ");
    }

    public function down(): void
    {
        Schema::table('dian_resolutions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_kind');
        });
    }
};
