<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta para corregir una nomina ya reportada a la DIAN.
 *
 * - `solidarity_sub` separa la subcuenta de subsistencia del fondo de
 *   solidaridad. Son dos porcentajes distintos y la DIAN los pide por aparte;
 *   hasta ahora solo se calculaba el 1% de solidaridad, asi que a quien gana
 *   mas de 16 SMLMV se le estaba descontando de menos.
 *
 * - `dian_needs_adjustment` marca la colilla que se re-liquido despues de
 *   haberla reportado. La correccion ante la DIAN no es reenviarla —eso no lo
 *   permite— sino emitir una nota de ajuste, y sin esta marca no hay forma de
 *   saber cuales quedaron desalineadas.
 *
 * - `payroll_note_next_consecutive` numera las notas de ajuste. El prefijo ya
 *   estaba; el consecutivo no, asi que fuera del set de pruebas no habia con
 *   que numerarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->decimal('solidarity_sub', 14, 2)->default(0)->after('solidarity_fund');
            $table->boolean('dian_needs_adjustment')->default(false)->after('dian_error_message');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('payroll_note_next_consecutive')->default(1)->after('payroll_note_prefix');
        });

        Schema::create('payroll_adjustment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_slip_id')->constrained()->cascadeOnDelete();

            // 1 reemplaza el documento anterior, 2 lo elimina.
            $table->unsignedTinyInteger('type_note');

            $table->string('prefix', 10)->nullable();
            $table->unsignedInteger('consecutive')->nullable();
            $table->string('cune')->nullable();

            // De que documento es correccion. Se copia y no se lee de la
            // colilla al vuelo: si esta se vuelve a ajustar mas adelante, la
            // nota tiene que seguir apuntando a lo que corrigio.
            $table->string('predecessor_prefix', 10)->nullable();
            $table->unsignedInteger('predecessor_consecutive')->nullable();
            $table->string('predecessor_cune')->nullable();
            $table->date('predecessor_issue_date')->nullable();

            $table->string('dian_status', 12)->default('pending');
            $table->string('dian_status_code', 10)->nullable();
            $table->text('dian_error_message')->nullable();
            $table->json('payload')->nullable();
            $table->json('dian_response')->nullable();
            $table->timestamp('dian_sent_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'payroll_slip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustment_notes');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payroll_note_next_consecutive');
        });

        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->dropColumn(['solidarity_sub', 'dian_needs_adjustment']);
        });
    }
};
