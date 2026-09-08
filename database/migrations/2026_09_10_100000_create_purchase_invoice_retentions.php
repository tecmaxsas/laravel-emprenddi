<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones en las facturas de compra.
 *
 * Las de venta existian desde el principio; las de compra no, ni la tabla.
 * Sin ellas, una empresa que le retiene a sus proveedores —cualquiera que sea
 * agente retenedor, que en Colombia es la mayoria— tenia que registrar la
 * factura por el total y arreglar la retencion con un asiento manual.
 *
 * OJO con la direccion, que es la contraria a la de venta:
 *
 *   - En una VENTA el cliente nos retiene. Para nosotros es un anticipo de
 *     impuesto: un activo, y cobramos menos.
 *   - En una COMPRA nosotros le retenemos al proveedor. Es un pasivo —esa
 *     plata se la debemos a la DIAN, no al proveedor— y le pagamos menos.
 *
 * Por eso `net_payable` es lo que de verdad se le paga al proveedor, y es
 * contra ese valor que se mide el saldo pendiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();

            // Copia del impuesto al momento de la factura: si despues le
            // cambian la tarifa, el historico contable no se mueve.
            $table->string('tax_code', 30);
            $table->string('tax_name', 150);
            $table->string('tax_type', 30);

            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('rate', 7, 4)->default(0);
            $table->decimal('amount', 18, 2)->default(0);

            $table->timestamps();

            $table->index('purchase_invoice_id');
            $table->index('tax_id');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('retention_total', 18, 2)->default(0)->after('total');
            // Arranca igual al total: las facturas que ya existen no tienen
            // retenciones, asi que lo que se le debe al proveedor es todo.
            $table->decimal('net_payable', 18, 2)->default(0)->after('retention_total');
        });

        DB::table('purchase_invoices')
            ->update(['net_payable' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['retention_total', 'net_payable']);
        });

        Schema::dropIfExists('purchase_invoice_retentions');
    }
};
