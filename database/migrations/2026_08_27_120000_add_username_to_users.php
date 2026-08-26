<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login por nombre de usuario ademas de correo.
 *
 * Algunos clientes crean sus cajeros con nombres genericos y no con correos
 * reales, asi que el correo deja de ser obligatorio y aparece username como
 * segundo identificador.
 *
 * username es unico GLOBAL, no por empresa: la pantalla de login no sabe a que
 * empresa pertenece quien entra, asi que si "CAJERO" pudiera repetirse entre
 * clientes no habria forma de resolver a quien autenticar. La convencion de
 * ponerle el sufijo -{company_id} al crearlo (CAJERO-153) mantiene los nombres
 * legibles dentro de cada empresa y hace que las colisiones casi no ocurran.
 *
 * email sigue siendo unico, pero al quedar nullable Postgres admite varios
 * NULL en el indice, que es justo lo que se necesita para los usuarios sin
 * correo. Los usuarios actuales no se tocan: siguen entrando con su correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->unique()->after('email');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
