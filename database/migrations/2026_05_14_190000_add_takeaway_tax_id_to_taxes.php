<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impuesto alternativo "para llevar". En CO algunos productos cambian de
 * tasa según el modo de servicio (ej: INC 8% comer aquí → IVA 19% para
 * llevar). Cuando una orden de restaurante es is_takeaway/is_delivery y
 * el impuesto base tiene takeaway_tax_id, se usa ese en su lugar.
 *
 * Si takeaway_tax_id es null, el impuesto se aplica igual en ambos modos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->foreignId('takeaway_tax_id')->nullable()->after('rate')
                ->constrained('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('takeaway_tax_id');
        });
    }
};
