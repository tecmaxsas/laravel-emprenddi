<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conecta cada parqueadero con la sede (location) que se usara para emitir
 * la factura del cobro: define que resolucion DIAN aplica y donde queda
 * registrada la venta.
 *
 * Tambien fija el tipo de factura por defecto (pos | electronic) que el
 * cobrador propone al cerrar la sesion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_lots', function (Blueprint $table) {
            $table->foreignId('default_location_id')->nullable()
                ->after('phone')
                ->constrained('locations')->nullOnDelete();
            $table->string('default_invoice_kind', 20)->default('pos')
                ->after('default_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('parking_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_location_id');
            $table->dropColumn('default_invoice_kind');
        });
    }
};
