<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activos fijos: vehículos, equipos, muebles, edificios, etc. Cada activo
 * tiene su costo original, vida útil en meses, valor residual, y se
 * deprecia mensualmente (método lineal MVP).
 *
 * Cuentas contables típicas (PUC Colombia):
 *   Activo:      1504 Terrenos, 1516 Maquinaria, 1524 Equipo oficina,
 *                1528 Computadores, 1540 Flota y transporte, etc.
 *   Depreciación acumulada: 1592 (contracuenta de activo)
 *   Gasto depreciación: 5160 Depreciaciones (gasto operativo)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();

            $table->string('code', 30);  // ej. AF-001
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Clasificación libre — tag, no es FK.
            $table->string('category', 50)->nullable();

            $table->date('purchased_at');
            $table->date('activated_at')->nullable();  // empieza a depreciar
            $table->date('disposed_at')->nullable();   // se da de baja / vende

            $table->decimal('cost', 18, 2);
            $table->decimal('residual_value', 18, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months');

            $table->enum('depreciation_method', ['straight_line'])->default('straight_line');

            // Saldo acumulado (snapshot, lo actualiza el engine al
            // contabilizar cada depreciación mensual)
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);

            // Cuentas contables del activo
            $table->foreignId('asset_account_id')
                ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_account_id')
                ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')
                ->constrained('accounts')->restrictOnDelete();

            $table->foreignId('purchase_invoice_id')->nullable()
                ->constrained('purchase_invoices')->nullOnDelete();

            $table->enum('status', ['active', 'disposed'])->default('active');

            // Datos de baja
            $table->decimal('disposal_sale_price', 18, 2)->nullable();
            $table->foreignId('disposal_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->text('disposal_notes')->nullable();

            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
