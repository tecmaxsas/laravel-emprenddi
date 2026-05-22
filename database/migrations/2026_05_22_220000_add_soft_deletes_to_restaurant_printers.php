<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado lógico para las impresoras. Una impresora que ya imprimió
 * comandas está referenciada por restaurant_kitchen_tickets con
 * restrictOnDelete, así que no puede borrarse físicamente sin romper
 * el historial. Con soft delete se retira de la lista conservando
 * las comandas históricas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_printers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_printers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
