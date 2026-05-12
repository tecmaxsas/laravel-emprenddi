<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasas de cambio diarias. rate = unidades de moneda BASE por 1 unidad
 * de la moneda destino. Ejemplo: si base=COP, USD rate=4200 significa
 * 4200 COP = 1 USD.
 *
 * Unique por (currency, date) — una tasa por moneda por día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('rate', 18, 6);
            $table->text('source')->nullable();  // 'BanRep', 'manual', etc.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['currency_id', 'date']);
            $table->index(['currency_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
