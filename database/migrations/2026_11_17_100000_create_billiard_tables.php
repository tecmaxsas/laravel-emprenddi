<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo Billares — catalogos.
 *
 * Se activa por empresa (companies.active_modules incluye 'billiards').
 * Sin esa activacion el modulo entero es invisible, igual que parqueadero
 * y hotel.
 *
 *   billiard_table_types  Catalogo por empresa: pool, tres bandas, mini
 *                         pool, rana, ping pong. Es lo que separa las
 *                         tarifas: la hora de una mesa de tres bandas no
 *                         vale lo que la de un pool.
 *   billiard_halls        Cada sala fisica. Separada de Sedes porque un
 *                         negocio puede tener dos salones en la misma
 *                         sede, o una sala que no es un punto de venta.
 *   billiard_tables       Las mesas. Un tile del mapa del terminal.
 *   billiard_rates        Tarifa con JSON de reglas (ver BilliardRateEngine).
 *
 * Las mesas se llaman `billiard_tables` aunque "table" ya signifique otra
 * cosa en una base de datos. La alternativa era inventarle un sinonimo
 * ("boards", "units") que nadie usa en la sala: el operario dice «la mesa
 * 4», y el codigo que no habla el idioma del negocio se lee mal para
 * siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billiard_table_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);                 // POOL, TRES_BANDAS, MINIPOOL...
            $table->string('name', 80);
            $table->string('icon', 50)->nullable();     // emoji para el tile
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('billiard_halls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();

            // Sede que factura. Sin esto el cobro no sabe que resolucion DIAN
            // usar; es el primer error que reporta un billar recien montado.
            $table->foreignId('default_location_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->string('default_invoice_kind', 20)->default('pos');

            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('billiard_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hall_id')->constrained('billiard_halls')->cascadeOnDelete();
            $table->foreignId('table_type_id')->nullable()
                ->constrained('billiard_table_types')->nullOnDelete();

            $table->string('code', 30);                  // "1", "M-04", "VIP-2"
            $table->string('zone', 50)->nullable();       // "Salón principal", "Terraza", "Segundo piso"
            $table->string('status', 20)->default('free');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['hall_id', 'code']);
            $table->index(['hall_id', 'status']);
            $table->index(['company_id', 'hall_id', 'zone']);
        });

        Schema::create('billiard_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hall_id')->constrained('billiard_halls')->cascadeOnDelete();

            // null = aplica a cualquier tipo de mesa de la sala.
            $table->foreignId('table_type_id')->nullable()
                ->constrained('billiard_table_types')->nullOnDelete();

            $table->string('name', 150);
            $table->string('kind', 20)->default('regular');  // regular | event | corporate

            // Reglas de calculo. Schema documentado en BilliardRateEngine.
            $table->json('config');

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'hall_id', 'table_type_id', 'active'], 'br_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billiard_rates');
        Schema::dropIfExists('billiard_tables');
        Schema::dropIfExists('billiard_halls');
        Schema::dropIfExists('billiard_table_types');
    }
};
