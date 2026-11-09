<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De que pantalla nacio la factura.
 *
 * Hacia falta para una cosa concreta: al corregir una venta hecha en el POS,
 * el sistema abria el formulario completo de facturacion —cliente, impuestos,
 * retenciones, resolucion DIAN— que es justo la pantalla de la que el cajero
 * se escapa usando el POS. La correccion tiene que ocurrir donde ocurrio la
 * venta.
 *
 * **No se puede deducir de `invoice_kind`.** Esa columna dice que tipo de
 * documento es (tiquete POS o factura electronica), no de donde salio: el POS
 * se puede configurar para emitir electronicas, y desde el formulario se puede
 * emitir un documento tipo POS. Son dos preguntas distintas y usar una por la
 * otra manda al cajero a la pantalla equivocada justo en los casos raros, que
 * son los que nadie prueba.
 *
 * Nullable porque las facturas viejas no lo saben con certeza; el relleno de
 * abajo es la mejor reconstruccion posible, no un dato original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('invoice_kind');
        });

        $this->rellenarLasQueYaExisten();

        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->index(['company_id', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'origin']);
            $table->dropColumn('origin');
        });
    }

    /**
     * Reconstruye el origen de lo ya facturado.
     *
     * Se va de lo mas especifico a lo mas general: primero las que tienen un
     * documento padre que las delata, al final la caja —que es lo que queda
     * cuando hubo un turno abierto y nada mas que lo explique— y el
     * formulario, que es donde cae todo lo demas.
     *
     * El orden importa: cada paso solo toca las que siguen en null, asi una
     * factura de parqueadero no termina marcada como POS por tener turno.
     */
    private function rellenarLasQueYaExisten(): void
    {
        $porLlave = [
            'order_taking' => 'order_taking_orders',
            'parking' => 'parking_sessions',
            'hotel' => 'hotel_stays',
        ];

        foreach ($porLlave as $origen => $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            DB::statement("
                UPDATE sale_invoices SET origin = ?
                WHERE origin IS NULL
                  AND id IN (SELECT sale_invoice_id FROM {$tabla} WHERE sale_invoice_id IS NOT NULL)
            ", [$origen]);
        }

        if (Schema::hasTable('hotel_reservation_groups')) {
            DB::statement("
                UPDATE sale_invoices SET origin = 'hotel'
                WHERE origin IS NULL
                  AND id IN (SELECT sale_invoice_id FROM hotel_reservation_groups WHERE sale_invoice_id IS NOT NULL)
            ");
        }

        // El restaurante guarda varias facturas por orden (las cuentas
        // divididas), asi que la llave es un arreglo, no una columna.
        if (Schema::hasTable('restaurant_orders')) {
            DB::statement("
                UPDATE sale_invoices SET origin = 'restaurant'
                WHERE origin IS NULL
                  AND id IN (
                      SELECT (jsonb_array_elements_text(invoice_ids))::bigint
                      FROM restaurant_orders
                      WHERE invoice_ids IS NOT NULL AND jsonb_typeof(invoice_ids) = 'array'
                  )
            ");
        }

        DB::statement("
            UPDATE sale_invoices SET origin = 'delivery_note'
            WHERE origin IS NULL AND from_delivery_note_id IS NOT NULL
        ");

        // Un turno de caja y ningun documento padre: salio del POS. El
        // formulario de facturacion nunca ata la venta a un turno.
        DB::statement("
            UPDATE sale_invoices SET origin = 'pos'
            WHERE origin IS NULL AND cash_register_session_id IS NOT NULL
        ");

        DB::statement("UPDATE sale_invoices SET origin = 'form' WHERE origin IS NULL");
    }
};
