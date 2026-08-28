<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones que se le aplican a un tercero.
 *
 * Los flags que ya existian en third_parties (is_iva_withholder,
 * is_ica_withholder...) dicen QUE clase de retenedor es el cliente, pero no
 * con que tarifa ni contra que cuenta contable. Esta tabla lo concreta: apunta
 * a los Tax del catalogo de la empresa, que ya traen tarifa y cuenta.
 *
 * Se reusa el mismo catalogo de retenciones de las facturas de venta, asi que
 * la retencion de un pedido y la de una factura salen del mismo sitio.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sin company_id: es una tabla pivote y el tercero ya ata a la empresa.
        // Ponerla obligaria a rellenarla a mano en cada sync() del pivote.
        Schema::create('third_party_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->cascadeOnDelete();
            $table->timestamps();

            // Un tercero no puede tener dos veces la misma retencion.
            $table->unique(['third_party_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_retentions');
    }
};
