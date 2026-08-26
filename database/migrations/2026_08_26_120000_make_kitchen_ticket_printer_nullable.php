<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite comandas sin impresora fisica.
 *
 * Hasta ahora, si ningun printer activo enrutaba la categoria del producto,
 * los items se marcaban como enviados y NO se generaba ticket: la comanda
 * simplemente no existia y la cocina se quedaba sin nada. Con la columna
 * nullable el ticket se crea igual y la comanda se imprime por la ventana del
 * navegador, como ya hace el tiquete de venta cuando no hay impresora de caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_kitchen_tickets', function (Blueprint $table) {
            $table->foreignId('restaurant_printer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_kitchen_tickets', function (Blueprint $table) {
            $table->foreignId('restaurant_printer_id')->nullable(false)->change();
        });
    }
};
