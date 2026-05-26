<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Identificación
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Código de cupón opcional — único por empresa cuando esta presente
            $table->string('code', 50)->nullable();

            // Tipo de promoción: porcentaje, monto fijo, BOGO (2x1, 3x2),
            // descuento por volumen escalonado, combo/bundle.
            $table->string('type', 30); // percentage, fixed_amount, bogo, volume_tier, bundle

            // Estado simple
            $table->boolean('active')->default(true);

            // Valor del descuento (cuando aplica simple)
            // percentage: 0-100 (% off)
            // fixed_amount: monto en moneda local
            // bogo/volume_tier/bundle: usa discount_data
            $table->decimal('discount_value', 14, 4)->nullable();

            // Configuracion compleja serializada (depende del type):
            // bogo: { buy_quantity: 2, get_quantity: 1, free_item_strategy: 'cheapest' }
            // volume_tier: { tiers: [{ min:4, max:9, percent:5 }, { min:10, max:null, percent:10 }] }
            // bundle: { items: [{ product_id, quantity }], bundle_price: 25000 }
            $table->json('discount_data')->nullable();

            // Alcance — a que productos aplica
            $table->string('scope', 20)->default('all'); // all, products, categories
            $table->json('scope_products')->nullable();   // array de product_ids
            $table->json('scope_categories')->nullable(); // array de category_ids

            // Condiciones de activacion
            $table->boolean('requires_code')->default(false); // si necesita ingresar codigo
            $table->integer('min_quantity')->nullable();      // cant minima en carrito
            $table->decimal('min_amount', 14, 2)->nullable(); // subtotal minimo

            // Modos de servicio (para restaurante)
            $table->boolean('applies_dine_in')->default(true);
            $table->boolean('applies_takeaway')->default(true);
            $table->boolean('applies_delivery')->default(true);

            // Vigencia temporal
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            // Dias de la semana ['mon','tue',...] — null = todos los dias
            $table->json('days_of_week')->nullable();
            // Happy hour: rango horario dentro de los dias permitidos
            $table->time('hour_from')->nullable();
            $table->time('hour_to')->nullable();

            // Limites de uso
            $table->integer('max_uses_total')->nullable();
            $table->integer('max_uses_per_customer')->nullable();
            $table->integer('usage_count')->default(0);

            // Comportamiento entre promociones
            $table->boolean('stackable')->default(false); // combinable con otras
            $table->integer('priority')->default(0);      // mayor = se evalua primero

            $table->timestamps();

            // Indices para el motor de aplicacion (filtrar por company + active + vigencia)
            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'code']);
            $table->index(['company_id', 'valid_from', 'valid_to']);

            // Unique de codigo por empresa (cuando esta presente)
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
