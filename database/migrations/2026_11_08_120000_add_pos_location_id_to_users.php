<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sede en la que abre caja cada cajero.
 *
 * Desde que solo los administradores eligen la sede al abrir caja, al resto
 * se le imponia la sede principal. En una empresa con dos locales eso mandaba
 * al cajero del segundo local a vender con el inventario, la numeracion y el
 * arqueo del primero, sin que nada en pantalla lo delatara.
 *
 * Nula = la principal, como hasta ahora. nullOnDelete para que borrar una sede
 * no tumbe al usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('pos_location_id')->nullable()->after('company_id')
                ->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_location_id');
        });
    }
};
