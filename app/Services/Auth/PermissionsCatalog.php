<?php

namespace App\Services\Auth;

/**
 * Catálogo canónico de permisos del sistema, agrupado por módulo.
 * Convención: <recurso>.<acción>  (e.g. products.manage, purchases.post)
 *
 * Usado por:
 *   - RolesSeeder para crear los Permission rows y asignar defaults a roles
 *   - RoleResource para mostrar el CheckboxList agrupado en UI
 *   - (futuro) Filament Resources canAccess() para enforcement
 */
class PermissionsCatalog
{
    public static function groups(): array
    {
        return [
            'Catálogo' => [
                'categories.view' => 'Ver categorías',
                'categories.manage' => 'Crear / editar / borrar categorías',
                'products.view' => 'Ver productos',
                'products.manage' => 'Crear / editar / borrar productos',
                'products.manage_initial_stock' => 'Cargar inventario inicial al crear producto',
            ],
            'Operación' => [
                'locations.view' => 'Ver sedes',
                'locations.manage' => 'Crear / editar / borrar sedes',
            ],
            'Contabilidad' => [
                'accounts.view' => 'Ver Plan de Cuentas',
                'accounts.manage' => 'Crear / editar cuentas customizadas',
                'taxes.view' => 'Ver impuestos',
                'taxes.manage' => 'Crear / editar impuestos',
                'third_parties.view' => 'Ver terceros (clientes/proveedores)',
                'third_parties.manage' => 'Crear / editar terceros',
                'journal_entries.view' => 'Ver asientos contables',
                'journal_entries.create' => 'Crear asientos manuales',
                'journal_entries.post' => 'Contabilizar (postear) asientos',
            ],
            'Inventario' => [
                'inventory.view' => 'Ver Kardex y stock',
                'inventory.adjust' => 'Hacer ajustes de inventario',
                'inventory.transfer' => 'Trasladar entre sedes',
            ],
            'Compras' => [
                'purchases.view' => 'Ver facturas de compra',
                'purchases.create' => 'Crear facturas de compra (borrador)',
                'purchases.post' => 'Contabilizar facturas de compra',
                'purchases.pay' => 'Registrar pagos a proveedores',
            ],
            'Ventas (futuro)' => [
                'sales.view' => 'Ver facturas de venta',
                'sales.create' => 'Crear facturas de venta',
                'sales.post' => 'Contabilizar facturas de venta',
                'sales.receive_payment' => 'Recibir pagos de clientes',
                'pos.use' => 'Usar el terminal POS',
                'pos.cash_close' => 'Cerrar turno de caja',
            ],
            'Reportes' => [
                'reports.journal_book' => 'Ver Libro Diario',
                'reports.general_ledger' => 'Ver Libro Mayor',
                'reports.trial_balance' => 'Ver Balance de Comprobación',
                'reports.kardex' => 'Ver Kardex',
            ],
            'Administración' => [
                'users.view' => 'Ver usuarios de la empresa',
                'users.manage' => 'Crear / editar / desactivar usuarios',
                'roles.view' => 'Ver roles y permisos',
                'roles.manage' => 'Editar roles y permisos',
                'company.settings' => 'Editar configuración de la empresa',
                'dian.manage' => 'Configurar facturación electrónica DIAN',
            ],
        ];
    }

    /**
     * Lista plana de todos los permission names.
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::groups() as $perms) {
            $out = array_merge($out, array_keys($perms));
        }

        return $out;
    }

    /**
     * Asignaciones default por rol — base de seguridad.
     *
     * admin: todo
     * manager: operacional + reportes (no users/roles)
     * accountant: contabilidad + reportes + pagos
     * cashier: pos + cobrar/pagar
     * seller: productos (view) + sales create + terceros
     */
    public static function defaultForRole(string $role): array
    {
        $all = self::all();

        return match ($role) {
            'admin' => $all,
            'manager' => array_diff($all, [
                'users.manage', 'roles.manage', 'roles.view', 'company.settings',
                'accounts.manage', 'taxes.manage', 'dian.manage',
            ]),
            'accountant' => [
                'accounts.view', 'accounts.manage', 'taxes.view', 'taxes.manage',
                'third_parties.view', 'third_parties.manage',
                'journal_entries.view', 'journal_entries.create', 'journal_entries.post',
                'purchases.view', 'purchases.post', 'purchases.pay',
                'sales.view', 'sales.post', 'sales.receive_payment',
                'reports.journal_book', 'reports.general_ledger', 'reports.trial_balance', 'reports.kardex',
                'inventory.view',
                'products.view', 'categories.view',
            ],
            'cashier' => [
                'pos.use', 'pos.cash_close',
                'sales.view', 'sales.create', 'sales.receive_payment',
                'products.view', 'categories.view',
                'third_parties.view', 'third_parties.manage',
                'inventory.view',
            ],
            'seller' => [
                'products.view', 'categories.view',
                'sales.view', 'sales.create',
                'third_parties.view', 'third_parties.manage',
                'inventory.view',
            ],
            default => [],
        };
    }
}
