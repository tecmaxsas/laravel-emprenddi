<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas suspendidas (parking) del POS.
 * Snapshot del carrito + cliente + pagos parciales en JSON, para que el
 * cajero pueda parquear una venta a medias y atender otro cliente, luego
 * recuperar y completar.
 *
 * No se contabiliza nada — son borradores volátiles que se borran al recuperar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspended_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('third_party_id')->nullable()->constrained('third_parties')->nullOnDelete();
            $table->string('name', 100)->nullable(); // etiqueta opcional ("Mesa 5", "María Pérez", etc.)
            $table->json('cart_snapshot');
            $table->json('payments_snapshot')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'location_id', 'seller_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspended_sales');
    }
};
