<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca al cliente de domicilio que se guardo sin cedula.
 *
 * third_parties.document_number es obligatorio y unico, pero quien pide un
 * domicilio por telefono casi nunca da la cedula: exigirsela para poder
 * guardarlo cambiaria la friccion de sitio. Entonces se guarda con el telefono
 * como identificador —que es como el restaurante lo reconoce de verdad— y esta
 * bandera dice que ese numero NO es un documento de identidad.
 *
 * Importa porque el numero viaja a la DIAN cuando la venta se factura
 * electronicamente. Un tercero marcado asi sirve como directorio para buscar y
 * precargar, pero la factura sigue saliendo a consumidor final hasta que
 * alguien registre la cedula de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->boolean('is_delivery_contact')->default(false)->after('is_customer');
        });
    }

    public function down(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->dropColumn('is_delivery_contact');
        });
    }
};
