<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeo de las casillas contables de nómina a las cuentas del PUC de la
 * empresa. El motor de contabilización de nómina usa este mapeo para
 * armar el asiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 50);
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_account_mappings');
    }
};
