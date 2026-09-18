<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En qué se le va la plata al negocio.
 *
 * Hasta ahora la única forma de agrupar gastos era la cuenta contable del PUC,
 * que le sirve al contador pero no al dueño: «5195 - Diversos» no le dice si se
 * le fue en domicilios o en mantenimiento. Y una empresa sin el módulo de
 * contabilidad no tenía ni siquiera eso.
 *
 * La categoría es la clasificación **del negocio**; la cuenta contable sigue
 * siendo la **fiscal**. Son dos preguntas distintas y por eso conviven: varias
 * categorías pueden ir a la misma cuenta del PUC sin ningún problema.
 */
class ExpenseCategory extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * Las que se encuentran en casi cualquier negocio pequeño colombiano.
     *
     * Vive aquí y no en la migración ni en el provisioner porque los dos la
     * necesitan, y una lista escrita dos veces termina siendo dos listas
     * distintas: la empresa creada ayer tendría categorías que la de hoy no,
     * y el reporte de una no se podría comparar con el de la otra.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const INICIALES = [
        ['Arriendo', 'Local, bodega o punto de venta'],
        ['Servicios públicos', 'Energía, agua, gas, internet y telefonía'],
        ['Nómina y prestaciones', 'Sueldos, seguridad social y parafiscales'],
        ['Transporte y domicilios', 'Fletes, mensajería y despachos'],
        ['Mantenimiento y reparaciones', 'Equipos, local y vehículos'],
        ['Papelería y aseo', 'Insumos de oficina y de limpieza'],
        ['Publicidad y mercadeo', 'Pauta, material publicitario y promociones'],
        ['Impuestos y tasas', 'Industria y comercio, prediales, cámara de comercio'],
        ['Comisiones y servicios bancarios', 'Cuotas de manejo, datáfono y transferencias'],
        ['Honorarios y asesorías', 'Contador, abogado, técnicos externos'],
        ['Otros gastos', 'Lo que no encaja en ninguna de las anteriores'],
    ];

    protected $fillable = [
        'company_id',
        'default_expense_account_id',
        'name',
        'description',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_expense_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Las que se pueden elegir al registrar un gasto.
     *
     * Una categoría desactivada sigue existiendo para que los gastos viejos no
     * se queden sin clasificación —y para que el reporte histórico no cambie—,
     * pero ya no se ofrece.
     *
     * @return array<int, string>
     */
    public static function opciones(?int $companyId = null): array
    {
        $companyId ??= auth()->user()?->company_id;

        if (! $companyId) {
            return [];
        }

        return static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
