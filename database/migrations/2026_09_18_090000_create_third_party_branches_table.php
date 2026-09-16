<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales de un tercero.
 *
 * Hay clientes —cadenas, empresas con varios puntos, distribuidores con CEDIs—
 * que operan varias sucursales y todas facturan bajo el **mismo NIT**. El índice
 * único de `third_parties` no deja repetir un documento, y hace bien: la factura
 * electrónica se emite al NIT, y duplicar el tercero con un documento inventado
 * parte la cartera, rompe el cupo de crédito y hace que el estado de cuenta no
 * cuadre con lo que el cliente cree deber.
 *
 * Así que la sucursal **no es un cliente**: es una dirección de entrega con datos
 * comerciales propios, colgando del único tercero que la DIAN conoce.
 *
 * Los campos que heredan (lista de precios, vendedor, cupo) van nulos a
 * propósito: nulo significa «lo que diga el tercero», que no es lo mismo que
 * cero. Un cupo en cero es «sin crédito»; un cupo nulo es «el del NIT».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_party_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained()->cascadeOnDelete();

            // El código que el cliente usa en sus órdenes de compra. Es por el
            // que pregunta cuando reclama un despacho, así que se busca por él.
            $table->string('code', 40)->nullable();
            $table->string('name', 150);

            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->unsignedInteger('dian_municipality_id')->nullable();

            $table->string('contact_person', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();

            // Franja en que reciben. En toma de pedidos pesa: despachar fuera de
            // horario es un viaje perdido.
            $table->string('delivery_horario', 150)->nullable();

            $table->foreignId('default_price_list_id')->nullable()
                ->constrained('order_taking_price_lists')->nullOnDelete();
            $table->foreignId('default_seller_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Sub-cupo dentro del cupo del NIT, nunca por encima.
            $table->decimal('credit_limit', 18, 2)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'third_party_id']);
            $table->index(['company_id', 'active']);

            // Dos sucursales del mismo cliente no pueden compartir código: es el
            // dato por el que se identifican entre ellos.
            $table->unique(['company_id', 'third_party_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_branches');
    }
};
