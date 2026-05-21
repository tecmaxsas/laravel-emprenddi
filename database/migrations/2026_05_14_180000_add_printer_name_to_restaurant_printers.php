<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre exacto de la impresora en Windows/SO, usado por el modo
 * 'browser' (QZ Tray). QZ Tray identifica impresoras por su nombre
 * tal como aparece en el sistema operativo del cajero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_printers', function (Blueprint $table) {
            $table->string('printer_name', 120)->nullable()->after('cups_queue');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_printers', function (Blueprint $table) {
            $table->dropColumn('printer_name');
        });
    }
};
