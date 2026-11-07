<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los productos favoritos: los que se venden todo el dia.
 *
 * En un mostrador real el 80% de las ventas sale de veinte productos, y el
 * cajero los busca uno por uno en una grilla de cientos o los teclea en el
 * buscador. Cada segundo ahi es con el cliente esperando.
 *
 * Una columna y no una tabla aparte porque es un atributo del producto, no una
 * relacion: no hay favoritos por usuario ni por sede. Si algun dia hiciera
 * falta distinguirlos por cajero, eso SI seria otra tabla — pero inventarla
 * hoy seria resolver un problema que nadie tiene.
 *
 * El indice es compuesto con company_id porque la consulta del POS siempre
 * filtra por empresa primero: un indice solo sobre `is_favorite` no se usaria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('is_sellable');
            $table->index(['company_id', 'is_favorite'], 'products_favoritos_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_favoritos_idx');
            $table->dropColumn('is_favorite');
        });
    }
};
