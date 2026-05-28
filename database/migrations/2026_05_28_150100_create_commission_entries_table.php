<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();

            // Venta que origino la comision
            $table->foreignId('sale_invoice_id')->constrained()->cascadeOnDelete();

            // Base sobre la que se calculo (subtotal sin IVA / total / utilidad
            // segun CommissionsSettings.base). Se guarda el monto resuelto.
            $table->decimal('base_amount', 16, 2);
            // Comision causada (suma de las lineas: base_linea * rate_linea)
            $table->decimal('amount', 16, 2);

            // Como se causo (snapshot del setting al momento):
            //  invoiced  → al postear la factura
            //  collected → cuando la factura quedo totalmente pagada
            $table->string('causation_basis', 20);
            $table->dateTime('causation_date');

            // Estado de liquidacion:
            //  pending  → causada, aun no liquidada/pagada
            //  settled  → incluida en una liquidacion pagada
            //  reversed → anulada (ej. factura anulada o devuelta)
            $table->string('status', 20)->default('pending');
            $table->foreignId('commission_settlement_id')->nullable()->constrained()->nullOnDelete();

            // Desglose por linea (json) para auditoria: cada item con
            // product_id, base, rate, amount. Permite explicar el calculo.
            $table->json('breakdown')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // Una factura genera UNA entry por vendedor. Unique lo asegura
            // (evita doble causacion si el hook corre dos veces).
            $table->unique(['sale_invoice_id', 'seller_user_id'], 'commission_entries_unique');
            $table->index(['company_id', 'seller_user_id', 'status']);
            $table->index(['company_id', 'causation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
    }
};
