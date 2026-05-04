<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates de impresión de facturas.
 * Una empresa puede tener N templates (POS 80mm, A4 estándar, etc).
 * Cada sede apunta a UN template (FK en locations.invoice_template_id).
 *
 * `settings` es un JSON con toggles agrupados (header/customer/lines/totals/footer)
 * que controla qué datos se renderizan en el ticket. Estructura definida en
 * App\Models\InvoiceTemplate::defaultSettings().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('paper_size', 20); // pos_58, pos_80, letter_half, letter, a5, a4
            $table->json('settings');
            $table->text('footer_text')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_templates');
    }
};
