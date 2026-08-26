<?php

namespace App\Support;

use App\Models\Company;

/**
 * Feature flags del módulo restaurante. Almacenados en
 * companies.settings.restaurant.enable_* (jsonb).
 *
 * Cuando una empresa activa el módulo restaurante, todas las features
 * arrancan habilitadas (defaults = true). El dueño/manager puede
 * apagar las que no use desde Configuraciones → Restaurante.
 *
 * Las features están definidas en self::FEATURES con su default y
 * descripción para la UI.
 */
class RestaurantSettings
{
    public const FEATURES = [
        'modifiers' => [
            'label' => 'Modificadores',
            'description' => 'Permite asociar grupos de modificadores (extras, sin cebolla, etc.) a los productos.',
            'default' => true,
        ],
        'tips' => [
            'label' => 'Propinas',
            'description' => 'Muestra los controles de propina (% sugerido o monto manual) en el panel de la orden.',
            'default' => true,
        ],
        'split_bill' => [
            'label' => 'División de cuenta',
            'description' => 'Permite dividir la cuenta por etiquetas (A, B, C…) y emitir una factura por cada tab.',
            'default' => true,
        ],
        'courses' => [
            'label' => 'Cursos / tiempos',
            'description' => 'Activa la secuencia Entrada → Principal → Postre y los botones de envío por curso a cocina.',
            'default' => true,
        ],
        'half_and_half' => [
            'label' => 'Mitad y mitad',
            'description' => 'Botón para combinar dos productos de la misma categoría en una línea (típico de pizzas).',
            'default' => true,
        ],
        'table_operations' => [
            'label' => 'Transferir / juntar mesas',
            'description' => 'Operaciones de mover una orden a otra mesa o fusionar dos órdenes en una.',
            'default' => true,
        ],
        'takeaway' => [
            'label' => 'Comer aquí / Para llevar',
            'description' => 'Botón para abrir órdenes sin mesa (pickup) y toggle de modo en órdenes existentes.',
            'default' => true,
        ],
        'reservations' => [
            'label' => 'Reservaciones',
            'description' => 'CRUD de reservas y strip de próximas reservas en el POS.',
            'default' => true,
        ],
        'delivery' => [
            'label' => 'Domicilios',
            'description' => 'Pedidos a domicilio con dirección, repartidor (driver) y estados de despacho.',
            'default' => true,
        ],
        'qr_menu' => [
            'label' => 'Carta QR pública',
            'description' => 'Permite armar una carta digital con diseño personalizado (colores, fuentes, imágenes) y publicarla via QR.',
            'default' => true,
        ],
        'kds' => [
            'label' => 'Pantalla de cocina (KDS)',
            'description' => 'Página Cocina (KDS) para que el cocinero administre items por estación.',
            'default' => true,
        ],
        'reports' => [
            'label' => 'Reportes restaurante',
            'description' => 'Reportes de propinas por mesero, top items y tiempos de cocina.',
            'default' => true,
        ],
        // Unica feature que arranca APAGADA: dejar el inventario en negativo
        // es una decision del negocio, no algo que deba pasar por defecto.
        'sell_without_stock' => [
            'label' => 'Vender sin inventario',
            'description' => 'Permite cobrar y facturar aunque el producto no tenga existencias, dejando el inventario en negativo. Útil en cocina, donde el plato se prepara al momento y el descargue de insumos no siempre está al día.',
            'default' => false,
        ],
    ];

    /**
     * Si la feature está habilitada para la empresa actual.
     * Si la empresa no tiene config (módulo recién activado), respeta el default.
     */
    public static function isEnabled(string $feature): bool
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return false;
        }

        $company = app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
        $settings = $company?->settings ?? [];

        $stored = data_get($settings, "restaurant.enable_{$feature}");
        if ($stored === null) {
            return (bool) self::FEATURES[$feature]['default'];
        }

        return (bool) $stored;
    }

    /**
     * Estado actual de todas las features para la UI de Settings.
     * Devuelve [feature_key => bool].
     */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $key) {
            $out[$key] = self::isEnabled($key);
        }
        return $out;
    }
}
