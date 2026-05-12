<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monedas. Cada empresa tiene su moneda base (típicamente COP) y puede
 * activar otras (USD, EUR, etc.). Las tasas de cambio se almacenan en
 * exchange_rates por fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 3);  // COP, USD, EUR, etc. ISO 4217
            $table->string('name', 50);
            $table->string('symbol', 5)->default('$');
            $table->unsignedTinyInteger('decimals')->default(2);

            $table->boolean('is_base')->default(false);  // COP típicamente
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_base']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
