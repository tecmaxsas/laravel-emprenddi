<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El monedero de Claude pasa de pesos a dólares.
 *
 * Se guardaba en pesos, convertido con la tasa del momento. Eso metía la tasa de
 * cambio en medio de una cuenta que no la necesita y tenía un efecto feo: subir
 * `AI_USD_TO_COP` de 4200 a 4400 hacía que el saldo YA recargado de un cliente
 * le rindiera menos, porque el costo se convertía al consumir y no al recargar.
 *
 * En dólares la cuenta cierra sola: Anthropic factura en dólares, Tecmax vende
 * en dólares —el cliente recarga 50, ve 50— y la tasa queda solo para mostrar un
 * equivalente aproximado en pesos.
 *
 * La precisión sube a seis decimales porque una respuesta puede costar
 * US$ 0,0008 y con dos decimales se cobraría cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La tasa con la que se convirtieron los valores que ya existen. Si la
        // configuración cambió desde entonces el equivalente no será exacto,
        // pero es lo más cercano que hay y el volumen es mínimo: la
        // funcionalidad se acaba de publicar.
        $tasa = max(1, (float) config('ai.usd_to_cop', 4200));

        Schema::table('ai_credit_movements', function (Blueprint $table) {
            $table->renameColumn('amount_cop', 'amount_usd');
        });

        Schema::table('ai_credit_movements', function (Blueprint $table) {
            $table->decimal('amount_usd', 14, 6)->change();
            $table->decimal('balance_after', 14, 6)->change();
        });

        DB::table('ai_credit_movements')->update([
            'amount_usd' => DB::raw("round(amount_usd / {$tasa}, 6)"),
            'balance_after' => DB::raw("round(balance_after / {$tasa}, 6)"),
        ]);

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->renameColumn('cost_cop', 'cost_usd');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            // Ojo: `change()` reemplaza la definición completa de la columna, así
            // que hay que repetir el default. Sin él, insertar un mensaje del
            // usuario —que no tiene costo— viola el NOT NULL.
            $table->decimal('cost_usd', 14, 6)->default(0)->change();
        });

        DB::table('ai_messages')->update([
            'cost_usd' => DB::raw("round(cost_usd / {$tasa}, 6)"),
        ]);
    }

    public function down(): void
    {
        $tasa = max(1, (float) config('ai.usd_to_cop', 4200));

        DB::table('ai_messages')->update([
            'cost_usd' => DB::raw("round(cost_usd * {$tasa}, 2)"),
        ]);

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->decimal('cost_usd', 14, 2)->default(0)->change();
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->renameColumn('cost_usd', 'cost_cop');
        });

        DB::table('ai_credit_movements')->update([
            'amount_usd' => DB::raw("round(amount_usd * {$tasa}, 2)"),
            'balance_after' => DB::raw("round(balance_after * {$tasa}, 2)"),
        ]);

        Schema::table('ai_credit_movements', function (Blueprint $table) {
            $table->decimal('amount_usd', 14, 2)->change();
            $table->decimal('balance_after', 14, 2)->change();
        });

        Schema::table('ai_credit_movements', function (Blueprint $table) {
            $table->renameColumn('amount_usd', 'amount_cop');
        });
    }
};
