<?php

namespace App\Models;

use App\Support\DashboardSections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencias del Escritorio (dashboard) de un usuario: qué secciones
 * oculta y en qué orden las quiere. Personal por usuario (no por empresa).
 */
class DashboardPreference extends Model
{
    protected $fillable = [
        'user_id', 'company_id', 'hidden_sections', 'section_order',
    ];

    protected function casts(): array
    {
        return [
            'hidden_sections' => 'array',
            'section_order' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Devuelve la lista ORDENADA de keys de secciones VISIBLES para el
     * usuario: filtradas por permiso, sin las ocultas, en el orden que
     * el usuario configuro (o el default si no hay preferencia).
     *
     * @return string[]
     */
    public static function visibleSectionsFor($user): array
    {
        $available = array_keys(DashboardSections::availableFor($user));
        if (empty($available)) {
            return [];
        }

        $pref = self::query()->where('user_id', $user->id)->first();
        $hidden = $pref?->hidden_sections ?? [];
        $order = $pref?->section_order ?? [];

        // Orden: primero las que el usuario ordeno (si siguen disponibles),
        // luego las disponibles que no estaban en su orden (nuevas secciones).
        $ordered = [];
        foreach ($order as $key) {
            if (in_array($key, $available, true) && ! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }
        foreach ($available as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        // Quitar las ocultas
        return array_values(array_filter($ordered, fn ($k) => ! in_array($k, $hidden, true)));
    }
}
