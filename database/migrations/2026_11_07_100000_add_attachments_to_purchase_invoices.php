<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto o el PDF de la factura del proveedor.
 *
 * POR QUE HACE FALTA. La factura de compra se digita a partir de un papel que
 * llega con el pedido, y ese papel es el soporte: cuando el contador pregunta
 * por que se descontó un IVA, o cuando el proveedor dice que cobro otra cosa,
 * lo que resuelve la discusion es el documento original. Hasta ahora vivia en
 * una carpeta, en un cajon o en el celular de alguien, y la mitad de las veces
 * no aparecia.
 *
 * JSON y no una columna de texto porque una factura puede llegar en varias
 * hojas, y una foto por hoja es lo normal. Es el mismo patron de
 * `parking_incidents.photo_paths`: un arreglo de rutas del disco publico.
 *
 * Los archivos van a `storage/app/public`, que es lo que el respaldo diario ya
 * empaqueta. Si fueran a otra carpeta, el dia que se restaure un respaldo la
 * contabilidad volveria sin sus soportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->json('attachment_paths')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('attachment_paths');
        });
    }
};
