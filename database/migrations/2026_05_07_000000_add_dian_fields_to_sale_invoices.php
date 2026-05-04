<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos DIAN para sale_invoices: rastro del envío a apidian + CUFE + QR.
 *
 *   dian_resolution_id: snapshot de la resolución usada (la sede puede cambiar
 *     después y la factura debe seguir refiriendo a la que se usó al postear).
 *   dian_status: pending|sent|accepted|rejected
 *   dian_status_code: código DIAN ("00"=ok, "115"=consec ya usado, etc)
 *   cufe: Código Único de Factura Electrónica (96 chars)
 *   qr_url: URL del catálogo DIAN para validación pública
 *   dian_response: respuesta cruda del API (debug + auditoría)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->foreignId('dian_resolution_id')->nullable()
                ->after('seller_user_id')
                ->constrained('dian_resolutions')->nullOnDelete();
            $table->string('dian_status', 20)->nullable()->after('dian_resolution_id');
            $table->string('dian_status_code', 10)->nullable();
            $table->string('cufe', 100)->nullable();
            $table->text('qr_url')->nullable();
            $table->json('dian_response')->nullable();
            $table->text('dian_error_message')->nullable();
            $table->timestamp('dian_sent_at')->nullable();

            $table->index(['company_id', 'dian_status']);
            $table->index('cufe');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dian_resolution_id');
            $table->dropColumn(['dian_status', 'dian_status_code', 'cufe', 'qr_url', 'dian_response', 'dian_error_message', 'dian_sent_at']);
        });
    }
};
