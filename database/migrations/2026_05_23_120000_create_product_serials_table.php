<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1 fila = 1 unidad física con número de serie. La tabla representa el
 * inventario serializado de productos que activan tracks_serials.
 *
 * Flujo:
 *   - in_stock  → al entrar por compra/ajuste in (sin sale_invoice_line_id)
 *   - sold      → al salir por venta (sale_invoice_line_id + sold_at)
 *   - returned  → devolución del cliente / al proveedor
 *   - defective → marcado por ajuste 'out' con reason damage/loss
 *   - reserved  → reserva temporal (futuro: separados/apartados)
 *
 * Unique (company_id, serial_number) → mismo serial puede repetirse entre
 * empresas pero no dentro de una. Index (company_id, status) acelera el
 * lookup en POS al escanear (buscar serial in_stock de mi empresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('serial_number', 100);
            $table->enum('status', ['in_stock', 'sold', 'returned', 'defective', 'reserved'])
                ->default('in_stock');

            // Trazabilidad: de dónde vino y a dónde fue
            $table->foreignId('purchase_invoice_line_id')->nullable()
                ->constrained('purchase_invoice_lines')->nullOnDelete();
            $table->foreignId('sale_invoice_line_id')->nullable()
                ->constrained('sale_invoice_lines')->nullOnDelete();
            $table->foreignId('inventory_adjustment_line_id')->nullable()
                ->constrained('inventory_adjustment_lines')->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Un serial dentro de una empresa es único. No lo hacemos por
            // (company_id, product_id, serial_number) porque queremos
            // detectar también si alguien intentara registrar el mismo
            // serial bajo dos productos distintos por error.
            $table->unique(['company_id', 'serial_number']);
            $table->index(['company_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index('serial_number'); // POS scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
    }
};
