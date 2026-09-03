<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogos de nomina electronica, copiados de la base de apidian.
 *
 * Van en tablas propias y no se reusan las de facturacion porque los ids NO
 * coinciden aunque el concepto sea el mismo: en el catalogo de facturacion el
 * PEP es 11 y en el de nomina es 9. Mezclarlos reportaria a cada trabajador
 * con un tipo de documento equivocado.
 *
 * El prefijo dian_ y la forma (id, code, name) siguen el patron de los
 * catalogos que ya existen. `dian_payroll_periods` no choca con nuestra tabla
 * payroll_periods, que es otra cosa: los periodos que la empresa liquida.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Periodicidad de pago: semanal, quincenal, mensual...
        Schema::create('dian_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 60);
            $table->timestamps();
        });

        // Tipo de documento del trabajador. Distinto del de facturacion.
        Schema::create('dian_payroll_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('dian_type_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 60);
            $table->timestamps();
        });

        Schema::create('dian_type_workers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 160);
            $table->timestamps();
        });

        Schema::create('dian_sub_type_workers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });

        // Deducciones de ley. percentage es la tarifa que le corresponde a
        // cada concepto; sirve para elegir la correcta segun el caso.
        Schema::create('dian_type_law_deductions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 100);
            $table->decimal('percentage', 6, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_type_law_deductions');
        Schema::dropIfExists('dian_sub_type_workers');
        Schema::dropIfExists('dian_type_workers');
        Schema::dropIfExists('dian_type_contracts');
        Schema::dropIfExists('dian_payroll_document_types');
        Schema::dropIfExists('dian_payroll_periods');
    }
};
