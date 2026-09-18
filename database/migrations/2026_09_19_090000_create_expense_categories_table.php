<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías de gasto.
 *
 * Hasta ahora la única forma de agrupar gastos era la cuenta contable del PUC,
 * que sirve para el contador pero no para el dueño: «5195 - Diversos» no le
 * dice si se le fue la plata en domicilios o en mantenimiento. Y una empresa
 * sin el módulo de contabilidad no tiene ni siquiera eso.
 *
 * La categoría es la clasificación del negocio; la cuenta contable sigue
 * siendo la clasificación fiscal. Son dos cosas distintas y por eso conviven:
 * varias categorías pueden ir a la misma cuenta.
 *
 * Cada categoría puede llevar su cuenta de gasto por defecto, para que elegir
 * «Arriendo» deje la imputación contable resuelta sin que nadie tenga que
 * saberse el PUC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // La cuenta del PUC a la que va este tipo de gasto. Opcional: una
            // empresa sin contabilidad no tiene por que elegirla.
            $table->foreignId('default_expense_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Dos categorias con el mismo nombre en la misma empresa son un
            // reporte partido en dos filas que deberian ser una.
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'active']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->after('cost_center_id')
                ->constrained('expense_categories')->nullOnDelete();

            $table->index(['company_id', 'expense_category_id']);
        });

        $this->sembrarCategorias();
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['expense_category_id']);
            $table->dropIndex(['company_id', 'expense_category_id']);
            $table->dropColumn('expense_category_id');
        });

        Schema::dropIfExists('expense_categories');
    }

    /**
     * Un juego inicial para cada empresa que ya existe.
     *
     * El listado sale de `ExpenseCategory::INICIALES`, el mismo que usa el
     * provisioner de empresas nuevas. Escrito dos veces terminaría siendo dos
     * listas distintas y el reporte de una empresa no se podría comparar con
     * el de otra.
     *
     * Se siembran para que el desplegable no arranque vacío: una lista vacía
     * obliga a interrumpir lo que se estaba haciendo para ir a crear la
     * primera categoría. Son borrables y desactivables.
     */
    private function sembrarCategorias(): void
    {
        $ahora = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $filas = [];

            foreach (ExpenseCategory::INICIALES as $orden => [$nombre, $descripcion]) {
                $filas[] = [
                    'company_id' => $companyId,
                    'name' => $nombre,
                    'description' => $descripcion,
                    'sort_order' => ($orden + 1) * 10,
                    'active' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            DB::table('expense_categories')->insert($filas);
        }
    }
};
