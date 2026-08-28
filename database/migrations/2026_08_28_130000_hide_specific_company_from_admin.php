<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;

/**
 * Oculta MAS DULCES COLOMBIA de los listados del superadmin.
 *
 * No hay interruptor en el panel a proposito: ocultar una empresa no es una
 * operacion del dia a dia, es una excepcion puntual. Se marca aqui para que
 * quede registrada en el historial del repositorio y no dependa de que alguien
 * recuerde haberla activado a mano.
 *
 * La empresa sigue operando con total normalidad: sus usuarios entran, factura
 * y su ficha se abre por URL directa. Solo deja de listarse.
 */
return new class extends Migration
{
    private const NIT = '805027332';

    public function up(): void
    {
        Company::withoutGlobalScopes()
            ->where('nit', self::NIT)
            ->update(['hidden_from_admin' => true]);
    }

    public function down(): void
    {
        Company::withoutGlobalScopes()
            ->where('nit', self::NIT)
            ->update(['hidden_from_admin' => false]);
    }
};
