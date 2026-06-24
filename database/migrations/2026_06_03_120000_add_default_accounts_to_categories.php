<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite asignar cuentas contables por defecto a una Categoria para que
 * los productos hereden la configuracion en cascada y no haya que editar
 * cuenta por cuenta.
 *
 * Cascada de resolucion:
 *   producto -> categoria -> categoria padre -> ... -> null (error al postear)
 *
 * NOTA: los impuestos (IVA) se mantienen individuales por producto porque
 * dentro de una misma categoria pueden coexistir tasas distintas (0%, 5%,
 * 19%, exento). La herencia es SOLO contable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('default_sale_account_id')->nullable()
                ->after('parent_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_cost_account_id')->nullable()
                ->after('default_sale_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_inventory_account_id')->nullable()
                ->after('default_cost_account_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_sale_account_id');
            $table->dropConstrainedForeignId('default_cost_account_id');
            $table->dropConstrainedForeignId('default_inventory_account_id');
        });
    }
};
