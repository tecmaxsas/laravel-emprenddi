<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impresoras térmicas configuradas para el restaurante. Conexión por
 * defecto: ESC/POS sobre TCP (ip + port 9100). El driver es PHP nativo
 * via fsockopen + librería mike42/escpos-php.
 *
 * Routing: cada impresora tiene un set de category_ids — los items
 * cuya category pertenezca a ese set se imprimen ahí. Una orden con
 * cerveza (categoría Bebidas → impresora Barra) y pizza (categoría
 * Comidas → impresora Cocina) genera DOS comandas, una a cada impresora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);  // "Cocina", "Barra", "Caja"
            $table->enum('purpose', ['kitchen', 'bar', 'cashier', 'other'])->default('kitchen');

            // Conexión ESC/POS sobre TCP.
            $table->enum('connection_type', ['network', 'cups', 'browser'])->default('network');
            $table->string('host', 100)->nullable();  // IP o hostname
            $table->unsignedSmallInteger('port')->default(9100);
            $table->string('cups_queue', 100)->nullable();  // si connection_type=cups

            // Filtro de categorías (array de category_id). Si vacío, recibe
            // TODOS los items que no estén ruteados a otra impresora.
            $table->jsonb('category_ids')->nullable();

            // Ancho del papel (en columnas de caracteres) — afecta formato.
            $table->unsignedSmallInteger('columns')->default(48);

            $table->boolean('open_cash_drawer')->default(false);  // si esta impresora abre cajón
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'location_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_printers');
    }
};
