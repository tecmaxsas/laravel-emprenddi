<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\InvoiceTemplate;

/**
 * Crea las plantillas de impresión de factura iniciales:
 *  - Ticket POS 58mm (default) — el rollo más común en el comercio pequeño.
 *  - Ticket POS 80mm — para las térmicas de rollo ancho.
 *  - Factura Carta — para impresión completa en oficina.
 *
 * Idempotente: si la empresa ya tiene plantillas, no toca nada (no se
 * asume que la primera deba llamarse de una forma específica).
 *
 * El campo settings usa InvoiceTemplate::defaultSettings() para que el
 * blade de impresión renderice todo por defecto (header, cliente,
 * líneas, totales, footer) sin requerir configuración manual previa.
 */
class InvoiceTemplateProvisioner
{
    public function provision(Company $company): int
    {
        $alreadyHas = InvoiceTemplate::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->exists();

        if ($alreadyHas) {
            return 0;
        }

        $base = InvoiceTemplate::defaultSettings();

        $created = 0;

        InvoiceTemplate::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Ticket POS 58mm',
            'description' => 'Plantilla térmica por defecto para el cajero POS.',
            'paper_size' => 'pos_58',
            'settings' => $base,
            'footer_text' => '¡Gracias por tu compra!',
            'is_default' => true,
            'active' => true,
        ]);
        $created++;

        InvoiceTemplate::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Ticket POS 80mm',
            'description' => 'Plantilla térmica para impresoras de rollo ancho.',
            'paper_size' => 'pos_80',
            'settings' => $base,
            'footer_text' => '¡Gracias por tu compra!',
            'is_default' => false,
            'active' => true,
        ]);
        $created++;

        InvoiceTemplate::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Factura Carta',
            'description' => 'Plantilla completa para impresión en oficina.',
            'paper_size' => 'letter',
            'settings' => $base,
            'footer_text' => 'Gracias por su confianza.',
            'is_default' => false,
            'active' => true,
        ]);
        $created++;

        return $created;
    }
}
