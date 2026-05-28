<?php

namespace App\Support;

/**
 * Catálogo de secciones del Escritorio (dashboard) personalizables por
 * usuario. Cada sección declara el permiso que la habilita: si el usuario
 * no lo tiene, la sección ni aparece en la personalización ni en el panel.
 *
 * El HERO (saludo) no es configurable — siempre se muestra.
 *
 * Usado por:
 *  - DashboardOverviewWidget para decidir qué secciones renderizar y en
 *    qué orden según las preferencias del usuario.
 *  - PersonalizarEscritorio (página) para listar las secciones que el
 *    usuario puede activar/desactivar/reordenar.
 */
class DashboardSections
{
    /**
     * key => [label, description, default_order, permission(s)].
     * permission puede ser:
     *  - string: un permiso requerido
     *  - array: el usuario necesita AL MENOS UNO (OR)
     *  - 'restaurant_module': caso especial (modulo restaurant + restaurant.use)
     */
    public const SECTIONS = [
        'kpis' => [
            'label' => 'Indicadores principales (KPIs)',
            'description' => 'Ventas de hoy y del mes, cartera por cobrar, comparativo vs. ayer.',
            'default_order' => 1,
            'permission' => ['sales.view', 'purchases.view'],
        ],
        'sales_chart' => [
            'label' => 'Gráfica de ventas (14 días)',
            'description' => 'Barras con las ventas diarias de las últimas dos semanas.',
            'default_order' => 2,
            'permission' => 'sales.view',
        ],
        'restaurant' => [
            'label' => 'Panel de restaurante',
            'description' => 'Mesas ocupadas, órdenes abiertas, domicilios en curso y ventas del día.',
            'default_order' => 3,
            'permission' => 'restaurant_module',
        ],
        'payroll' => [
            'label' => 'Resumen de nómina',
            'description' => 'Empleados activos, última nómina liquidada y prestaciones pendientes.',
            'default_order' => 4,
            'permission' => ['payroll.employees.view', 'payroll.periods.view'],
        ],
        'activity' => [
            'label' => 'Actividad reciente',
            'description' => 'Últimas ventas y compras registradas.',
            'default_order' => 5,
            'permission' => ['sales.view', 'purchases.view'],
        ],
    ];

    /**
     * ¿El usuario tiene permiso para ver esta sección?
     */
    public static function userCanSee(string $key, $user): bool
    {
        if (! isset(self::SECTIONS[$key]) || ! $user) {
            return false;
        }
        $permission = self::SECTIONS[$key]['permission'];

        if ($permission === 'restaurant_module') {
            return \App\Support\ModuleGate::active('restaurant') && $user->can('restaurant.use');
        }
        if (is_array($permission)) {
            foreach ($permission as $p) {
                if ($user->can($p)) return true;
            }
            return false;
        }
        return (bool) $user->can($permission);
    }

    /**
     * Secciones que el usuario PUEDE ver (filtradas por permiso),
     * indexadas por key. Conserva el orden de definición.
     *
     * @return array<string, array>
     */
    public static function availableFor($user): array
    {
        $out = [];
        foreach (self::SECTIONS as $key => $meta) {
            if (self::userCanSee($key, $user)) {
                $out[$key] = $meta;
            }
        }
        return $out;
    }
}
