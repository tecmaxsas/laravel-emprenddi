<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observaciones que SÍ se imprimen en la factura.
 *
 * Hacía falta una columna nueva porque las dos que ya había no sirven:
 *
 *  - `notes` está rotulado «Notas internas» en el formulario de factura y
 *    ahí es donde `SaleInvoiceDeleter` escribe el motivo de una anulación.
 *    Imprimirlo haría que toda factura con una anotación interna empezara a
 *    mostrársela al cliente, incluidas las que nunca se escribieron para
 *    que él las leyera.
 *
 *  - `description` es el concepto del documento («Parqueo placa ABC123»),
 *    lo que ya sale como línea del tiquete. Meterle ahí la observación
 *    mezclaría dos cosas distintas en un solo campo.
 *
 * Nace con el parqueadero —el cajero escribe «pagó con billete de 50» o
 * «se le cobró media hora» y necesita que el cliente lo vea—, pero es de
 * las ventas en general: cualquier factura puede llevarla y el formulario
 * la ofrece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->text('observations')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropColumn('observations');
        });
    }
};
