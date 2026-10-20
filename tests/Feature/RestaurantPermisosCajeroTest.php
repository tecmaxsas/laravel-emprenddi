<?php

namespace Tests\Feature;

use App\Services\Auth\PermissionsCatalog;
use Tests\TestCase;

/**
 * Lo que un cajero NO puede hacer en el POS de restaurante.
 *
 * Cerrar una cuenta sin facturar —«casa invita»— y cancelar una orden sin
 * cobrar son las dos formas de que un consumo salga del restaurante sin
 * generar venta. Quien decide regalar o anular es quien responde por la
 * caja, no quien la opera.
 *
 * Son permisos, no botones escondidos: el POS los revisa en pantalla y el
 * servidor los vuelve a revisar al ejecutar. Esconder el botón sin comprobar
 * atrás deja la puerta abierta a quien sepa la URL.
 */
class RestaurantPermisosCajeroTest extends TestCase
{
    private const SENSIBLES = [
        'restaurant.order.close_without_invoice',
        'restaurant.order.cancel',
    ];

    /** El cajero no los tiene. */
    public function test_el_cajero_no_puede_regalar_ni_anular(): void
    {
        $cajero = PermissionsCatalog::defaultForRole('cashier');

        $this->assertNotEmpty($cajero, 'No se encontró la plantilla del rol cajero.');

        foreach (self::SENSIBLES as $permiso) {
            $this->assertNotContains($permiso, $cajero,
                "El cajero no debería poder «{$permiso}»: quien regala o anula responde por la caja.");
        }
    }

    /** Pero alguien sí: si nadie los tiene, la función no existe. */
    public function test_el_administrador_si_puede(): void
    {
        foreach (self::SENSIBLES as $permiso) {
            $quienes = collect(['admin', 'manager', 'cashier', 'seller'])
                ->filter(fn (string $rol) => in_array(
                    $permiso, PermissionsCatalog::defaultForRole($rol), true
                ));

            $this->assertNotEmpty($quienes,
                "Nadie puede «{$permiso}»: entonces la función no se puede usar.");
        }
    }

    /**
     * El servidor los revisa, no solo la pantalla.
     *
     * Esconder el botón es comodidad; la que protege es la comprobación de
     * atrás, porque la acción de Livewire se puede invocar sin pasar por él.
     */
    public function test_el_servidor_vuelve_a_revisar(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Pages/Restaurant/RestaurantPos.php')
        );

        foreach (self::SENSIBLES as $permiso) {
            $this->assertStringContainsString("can('{$permiso}')", $fuente,
                "La acción de «{$permiso}» no comprueba el permiso en el servidor.");
        }
    }

    /** Y la pantalla no muestra lo que el usuario no puede usar. */
    public function test_la_pantalla_oculta_lo_que_no_se_puede(): void
    {
        $vista = file_get_contents(
            resource_path('views/filament/app/pages/restaurant/pos.blade.php')
        );

        foreach (self::SENSIBLES as $permiso) {
            $this->assertStringContainsString("@can('{$permiso}')", $vista,
                "El botón de «{$permiso}» se muestra a todo el mundo.");
        }
    }
}
