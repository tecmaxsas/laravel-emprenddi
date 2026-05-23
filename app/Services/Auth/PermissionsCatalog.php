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
                'invoice_templates.view' => 'Ver plantillas de impresión',
                'invoice_templates.manage' => 'Crear / editar / borrar plantillas de impresión',
                'printers.manage' => 'Configurar impresoras térmicas (comandas y tickets)',
            ],
            'Contabilidad' => [
                'accounts.view' => 'Ver Plan de Cuentas',
                'accounts.manage' => 'Crear / editar cuentas customizadas',
                'taxes.view' => 'Ver impuestos',
                'taxes.manage' => 'Crear / editar impuestos',
                'payment_methods.view' => 'Ver métodos de pago',
                'payment_methods.manage' => 'Crear / editar métodos de pago',
                'third_parties.view' => 'Ver terceros (clientes/proveedores)',
                'third_parties.manage' => 'Crear / editar terceros',
                'journal_entries.view' => 'Ver asientos contables',
                'journal_entries.create' => 'Crear asientos manuales',
                'journal_entries.post' => 'Contabilizar (postear) asientos',
                'partners.view' => 'Ver libro de socios',
                'partners.manage' => 'Registrar socios y movimientos de capital',
                'exogena.manage' => 'Configurar conceptos de información exógena',
            ],
            'Inventario' => [
                'inventory.view' => 'Ver Kardex y stock',
                'inventory.adjust' => 'Hacer ajustes de inventario',
                'inventory.transfer' => 'Trasladar entre sedes',
                'serials.view' => 'Ver seriales (consulta para garantía)',
                'serials.manage' => 'Editar / corregir seriales',
            ],
            'Compras' => [
                'purchases.view' => 'Ver facturas de compra',
                'purchases.create' => 'Crear facturas de compra (borrador)',
                'purchases.post' => 'Contabilizar facturas de compra',
                'purchases.pay' => 'Registrar pagos a proveedores',
                'support_documents.view' => 'Ver documentos soporte',
                'support_documents.create' => 'Crear documentos soporte (borrador)',
                'support_documents.post' => 'Contabilizar documentos soporte',
            ],
            'Ventas (futuro)' => [
                'sales.view' => 'Ver facturas de venta',
                'sales.create' => 'Crear facturas de venta',
                'sales.post' => 'Contabilizar facturas de venta',
                'sales.receive_payment' => 'Recibir pagos de clientes',
                'sales.send_dian' => 'Enviar facturas a DIAN',
                'quotations.view' => 'Ver cotizaciones',
                'quotations.manage' => 'Crear / editar / cambiar estado de cotizaciones',
                'quotations.convert' => 'Convertir cotizaciones en facturas',
                'delivery_notes.view' => 'Ver remisiones',
                'delivery_notes.manage' => 'Crear / editar / cancelar remisiones',
                'delivery_notes.dispatch' => 'Despachar remisiones (descarga inventario)',
                'delivery_notes.bill' => 'Convertir remisiones en facturas',
                'credit_debit_notes.view' => 'Ver notas crédito y débito',
                'credit_debit_notes.create' => 'Crear notas crédito y débito',
                'credit_debit_notes.post' => 'Contabilizar notas crédito y débito',
                'credit_debit_notes.send_dian' => 'Enviar NC/ND a DIAN',
                'pos.use' => 'Usar el terminal POS',
                'pos.cash_close' => 'Cerrar turno de caja',
                'pos.resolutions.manage' => 'Configurar resoluciones POS y asignarlas a sedes',
                'pos.discount.approve' => 'Aprobar descuentos del POS que exceden el umbral',
            ],
            'Restaurante' => [
                'restaurant.use' => 'Usar el POS de restaurante (tomar pedidos)',
                'restaurant.kitchen' => 'Ver y operar la pantalla de cocina (KDS)',
                'restaurant.tables.manage' => 'Configurar zonas, mesas e impresoras',
                'restaurant.modifiers.manage' => 'Configurar grupos de modificadores',
                'restaurant.reservations.manage' => 'Crear y gestionar reservaciones',
                'restaurant.delivery.manage' => 'Operar pedidos de delivery (despacho, asignar drivers)',
                'restaurant.bill.split' => 'Dividir cuentas',
                'restaurant.order.close_without_invoice' => 'Cerrar orden sin facturar (casa invita)',
                'restaurant.order.cancel' => 'Cancelar/anular orden sin cobrar',
                'restaurant.menu.manage' => 'Crear y editar carta QR pública',
                'restaurant.reports.view' => 'Ver reportes de restaurante (propinas, items, tiempos)',
            ],
            'Nómina' => [
                'payroll.employees.view' => 'Ver empleados y contratos',
                'payroll.employees.manage' => 'Crear / editar empleados y contratos',
                'payroll.periods.view' => 'Ver liquidaciones de nómina',
                'payroll.periods.manage' => 'Crear, liquidar y contabilizar nómina',
                'payroll.parameters.manage' => 'Configurar parámetros de nómina',
                'payroll.accounts.manage' => 'Configurar cuentas contables de nómina',
                'payroll.settlements.manage' => 'Liquidar y pagar prestaciones sociales',
            ],
            'Reportes' => [
                'reports.journal_book' => 'Ver Libro Diario',
                'reports.general_ledger' => 'Ver Libro Mayor',
                'reports.trial_balance' => 'Ver Balance de Comprobación',
                'reports.kardex' => 'Ver Kardex',
                'reports.sales' => 'Ver Reporte de Ventas por Período',
                'reports.accounts_receivable' => 'Ver Cartera (Cuentas por Cobrar)',
                'reports.cash_closings' => 'Ver Cierres de Caja Históricos',
                'exogena.view' => 'Ver y generar Información Exógena',
            ],
            'Administración' => [
                'users.view' => 'Ver usuarios de la empresa',
                'users.manage' => 'Crear / editar / desactivar usuarios',
                'roles.view' => 'Ver roles y permisos',
                'roles.manage' => 'Editar roles y permisos',
                'company.settings' => 'Editar configuración de la empresa',
                'pos.settings' => 'Configurar comportamiento del POS',
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
                'accounts.manage', 'taxes.manage', 'dian.manage', 'payment_methods.manage',
                'pos.settings',
            ]),
            'accountant' => [
                'accounts.view', 'accounts.manage', 'taxes.view', 'taxes.manage',
                'payment_methods.view', 'payment_methods.manage',
                'third_parties.view', 'third_parties.manage',
                'journal_entries.view', 'journal_entries.create', 'journal_entries.post',
                'partners.view', 'partners.manage',
                'purchases.view', 'purchases.post', 'purchases.pay',
                'support_documents.view', 'support_documents.post',
                'sales.view', 'sales.post', 'sales.receive_payment', 'sales.send_dian',
                'quotations.view', 'quotations.manage', 'quotations.convert',
                'delivery_notes.view', 'delivery_notes.manage', 'delivery_notes.dispatch', 'delivery_notes.bill',
                'credit_debit_notes.view', 'credit_debit_notes.create', 'credit_debit_notes.post', 'credit_debit_notes.send_dian',
                'reports.journal_book', 'reports.general_ledger', 'reports.trial_balance', 'reports.kardex',
                'reports.sales', 'reports.accounts_receivable', 'reports.cash_closings',
                'exogena.view', 'exogena.manage',
                'payroll.employees.view', 'payroll.employees.manage',
                'payroll.periods.view', 'payroll.periods.manage',
                'payroll.parameters.manage', 'payroll.accounts.manage', 'payroll.settlements.manage',
                'inventory.view',
                'serials.view',
                'products.view', 'categories.view',
            ],
            'cashier' => [
                'pos.use', 'pos.cash_close',
                'sales.view', 'sales.create', 'sales.receive_payment',
                'products.view', 'categories.view',
                'third_parties.view', 'third_parties.manage',
                'inventory.view',
                'serials.view',
                // Restaurant: el cajero/mesero usa el POS de mesas y la cocina
                'restaurant.use', 'restaurant.kitchen', 'restaurant.bill.split',
                'restaurant.delivery.manage', 'restaurant.reservations.manage',
                'restaurant.menu.manage',
            ],
            // Contador externo del Portal Contador. Lectura amplia + edición
            // contable (postear, anular, ajustes). No puede crear ventas/POS
            // ni tocar settings de empresa.
            'accountant_external' => [
                'accounts.view', 'accounts.manage',
                'taxes.view',
                'payment_methods.view',
                'third_parties.view',
                'journal_entries.view', 'journal_entries.create', 'journal_entries.post',
                'partners.view',
                'purchases.view', 'purchases.post', 'purchases.pay',
                'support_documents.view', 'support_documents.post',
                'sales.view', 'sales.post', 'sales.receive_payment',
                'quotations.view',
                'delivery_notes.view',
                'credit_debit_notes.view', 'credit_debit_notes.create', 'credit_debit_notes.post', 'credit_debit_notes.send_dian',
                'reports.journal_book', 'reports.general_ledger', 'reports.trial_balance', 'reports.kardex',
                'reports.sales', 'reports.accounts_receivable', 'reports.cash_closings',
                'exogena.view', 'exogena.manage',
                'payroll.employees.view', 'payroll.employees.manage',
                'payroll.periods.view', 'payroll.periods.manage',
                'payroll.parameters.manage', 'payroll.accounts.manage', 'payroll.settlements.manage',
                'inventory.view',
                'serials.view',
                'products.view', 'categories.view',
            ],
            'seller' => [
                'products.view', 'categories.view',
                'sales.view', 'sales.create',
                'quotations.view', 'quotations.manage', 'quotations.convert',
                'third_parties.view', 'third_parties.manage',
                'inventory.view',
                'serials.view',
            ],
            default => [],
        };
    }
}
