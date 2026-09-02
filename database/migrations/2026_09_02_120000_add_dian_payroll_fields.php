<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que falta para reportar la nomina a la DIAN.
 *
 * Numeracion en la empresa y no en dian_resolutions: la nomina electronica no
 * lleva resolucion de la DIAN como la facturacion. El empleador define su
 * propio prefijo y consecutivo, asi que no tiene sentido colgarlo del
 * mecanismo de resoluciones por sede.
 *
 * Estado de transmision en la colilla, con la misma forma que sale_invoices
 * (dian_status, dian_response, dian_sent_at...) pero con CUNE en lugar de
 * CUFE, que es como se llama en nomina.
 *
 * Los datos del trabajador que pide el payload y no teniamos: municipio DIAN,
 * tipo y subtipo de trabajador, y pension de alto riesgo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('payroll_prefix', 10)->nullable()->after('dv');
            $table->unsignedBigInteger('payroll_next_consecutive')->default(1)->after('payroll_prefix');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('dian_municipality_id')->nullable()->after('city')
                ->constrained('dian_municipalities')->nullOnDelete();
            $table->unsignedInteger('payroll_type_worker_id')->nullable()->after('dian_municipality_id');
            $table->unsignedInteger('payroll_sub_type_worker_id')->nullable()->after('payroll_type_worker_id');
            $table->boolean('high_risk_pension')->default(false)->after('payroll_sub_type_worker_id');
        });

        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable()->after('employment_contract_id');
            $table->unsignedBigInteger('consecutive')->nullable()->after('prefix');

            $table->string('dian_status', 20)->nullable()->after('consecutive');
            $table->string('dian_status_code', 20)->nullable()->after('dian_status');
            $table->string('cune')->nullable()->after('dian_status_code');
            $table->text('qr_url')->nullable()->after('cune');
            $table->json('dian_response')->nullable()->after('qr_url');
            $table->text('dian_error_message')->nullable()->after('dian_response');
            $table->timestamp('dian_sent_at')->nullable()->after('dian_error_message');

            $table->index(['company_id', 'dian_status']);
            // Un mismo consecutivo no se puede usar dos veces en la empresa.
            $table->unique(['company_id', 'prefix', 'consecutive']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'prefix', 'consecutive']);
            $table->dropIndex(['company_id', 'dian_status']);
            $table->dropColumn([
                'prefix', 'consecutive', 'dian_status', 'dian_status_code',
                'cune', 'qr_url', 'dian_response', 'dian_error_message', 'dian_sent_at',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dian_municipality_id');
            $table->dropColumn(['payroll_type_worker_id', 'payroll_sub_type_worker_id', 'high_risk_pension']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['payroll_prefix', 'payroll_next_consecutive']);
        });
    }
};
