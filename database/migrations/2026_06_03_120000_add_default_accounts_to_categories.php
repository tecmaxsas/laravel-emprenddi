<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite asignar cuentas contables e impuestos por defecto a una Categoria
 * para que los productos hereden la configuracion en cascada y no haya que
 * editar cuenta por cuenta.
 *
 * Cascada de resolucion:
 *   producto -> categoria -> categoria padre -> ... -> null (error al postear)
 *
 * Todas las columnas son nullable y las FKs usan nullOnDelete: si la cuenta
 * referenciada se elimina, la categoria queda sin cuenta por defecto y los
 * productos vuelven a su override (o fallan al postear si tampoco lo tienen).
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
            $table->foreignId('default_sale_tax_id')->nullable()
                ->after('default_inventory_account_id')->constrained('taxes')->nullOnDelete();
            $table->foreignId('default_purchase_tax_id')->nullable()
                ->after('default_sale_tax_id')->constrained('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_sale_account_id');
            $table->dropConstrainedForeignId('default_cost_account_id');
            $table->dropConstrainedForeignId('default_inventory_account_id');
            $table->dropConstrainedForeignId('default_sale_tax_id');
            $table->dropConstrainedForeignId('default_purchase_tax_id');
        });
    }
};
