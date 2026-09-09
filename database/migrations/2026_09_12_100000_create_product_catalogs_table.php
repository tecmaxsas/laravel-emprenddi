<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo público de productos: un enlace que la empresa comparte con sus
 * clientes.
 *
 * La decisión de fondo es que **no guarda productos**. No hay tabla pivote ni
 * copia de precios: el catálogo es un filtro guardado (qué categorías, si
 * muestra precios, cómo se ve) y los productos se consultan en vivo cada vez
 * que alguien abre el enlace. Por eso «se actualiza solo»: no hay nada que
 * sincronizar, ni un botón de regenerar que alguien vaya a olvidar.
 *
 * El costo de esa decisión es que la página consulta la base en cada visita.
 * Se paga con índices y paginación, no con una copia que se desactualiza.
 *
 * El `slug` es único en toda la plataforma, no por empresa: la URL no lleva el
 * id de la empresa, así que dos negocios no pueden llamarse igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('slug', 60)->unique();
            $table->string('subtitle', 200)->nullable();

            // Propios del catálogo. Si están vacíos se cae al logo de la
            // empresa: casi nadie va a querer uno distinto, pero quien lo
            // quiera no debería tener que cambiar el de sus facturas.
            $table->string('logo_path', 255)->nullable();
            $table->string('header_image_path', 255)->nullable();

            // Colores, fuente, estilo de cabecera y forma de la grilla.
            $table->jsonb('theme')->nullable();

            // Qué se muestra.
            $table->boolean('show_prices')->default(true);
            $table->boolean('show_codes')->default(false);
            $table->boolean('show_stock')->default(false);
            $table->boolean('only_with_image')->default(false);

            // Vacío = todas. Ids de categoría, para publicar solo una parte.
            $table->jsonb('category_ids')->nullable();

            // Contacto. Vacíos = los de la empresa.
            $table->string('whatsapp', 30)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->text('footer_text')->nullable();

            // Por defecto NO indexable: el enlace se comparte con clientes, no
            // se publica en Google. Quien quiera aparecer ahí lo activa.
            $table->boolean('allow_indexing')->default(false);

            // Apagado = la URL responde 404 sin borrar la configuración.
            $table->boolean('active')->default(true);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalogs');
    }
};
