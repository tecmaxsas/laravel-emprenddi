<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora del set de pruebas de nomina.
 *
 * La habilitacion pide 10 nominas y 10 notas de ajuste, y hay que poder
 * retomarla: la DIAN valida de forma asincrona, asi que un envio puede fallar
 * y reintentarse mas tarde sin volver a mandar los que ya pasaron.
 *
 * Sin esta tabla no habia forma de saber que se envio: el consecutivo suelto
 * en la configuracion decia cuantos, pero no cuales ni con que resultado, y la
 * nota de ajuste necesita el CUNE y la fecha de la nomina que reemplaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_payroll_test_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // 'nomina' o 'nota'. Cada nota reemplaza a la nomina del mismo slot.
            $table->string('kind', 10);
            $table->unsignedSmallInteger('slot');

            $table->string('prefix', 10)->nullable();
            $table->unsignedInteger('consecutive')->nullable();

            // El CUNE es lo que la nota necesita para apuntar a su predecesora.
            $table->string('cune')->nullable();
            $table->string('zip_key')->nullable();
            $table->date('issue_date')->nullable();

            $table->string('status', 12)->default('pendiente');
            $table->text('error_message')->nullable();

            $table->json('payload')->nullable();
            $table->json('response')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'kind', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_payroll_test_documents');
    }
};
