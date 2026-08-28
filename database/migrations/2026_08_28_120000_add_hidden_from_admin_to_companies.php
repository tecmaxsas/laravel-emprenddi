<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compañías que no deben aparecer en los listados del superadmin.
 *
 * No es un borrado ni una desactivación: la empresa opera con total
 * normalidad y su ficha sigue accesible entrando por URL directa. Solo deja
 * de listarse en el panel de administración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('hidden_from_admin')
                ->default(false)
                ->after('active')
                ->comment('Oculta la empresa de los listados del superadmin (sigue accesible por URL)');

            $table->index('hidden_from_admin');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['hidden_from_admin']);
            $table->dropColumn('hidden_from_admin');
        });
    }
};
