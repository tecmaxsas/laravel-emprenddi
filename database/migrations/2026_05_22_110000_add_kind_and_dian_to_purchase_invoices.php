<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documento Soporte en adquisiciones — la empresa emite este documento
 * electrónico DIAN al comprar a proveedores no obligados a facturar.
 * Contablemente es idéntico a una factura de compra, así que reusa la
 * tabla purchase_invoices con un discriminador `kind` y columnas para
 * la transmisión a DIAN (se usan en la siguiente iteración).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            // 'invoice' = factura de compra recibida del proveedor.
            // 'support_document' = documento soporte emitido por la empresa.
            $table->string('kind', 20)->default('invoice')->after('company_id');

            // Resolución DIAN tipo 4 que numera el documento soporte.
            $table->foreignId('dian_resolution_id')->nullable()->after('number')
                ->constrained('dian_resolutions')->nullOnDelete();

            // Estado de la transmisión electrónica a DIAN.
            $table->string('dian_status', 20)->nullable()->after('notes');
            $table->string('dian_status_code', 10)->nullable()->after('dian_status');
            $table->timestamp('dian_sent_at')->nullable()->after('dian_status_code');
            // CUDS — Código Único del Documento Soporte (análogo al CUFE).
            $table->string('cufe', 191)->nullable()->after('dian_sent_at');
            $table->text('qr_url')->nullable()->after('cufe');
            $table->text('dian_error_message')->nullable()->after('qr_url');
            $table->jsonb('dian_response')->nullable()->after('dian_error_message');

            $table->index(['company_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind', 'status']);
            $table->dropConstrainedForeignId('dian_resolution_id');
            $table->dropColumn([
                'kind', 'dian_status', 'dian_status_code', 'dian_sent_at',
                'cufe', 'qr_url', 'dian_error_message', 'dian_response',
            ]);
        });
    }
};
