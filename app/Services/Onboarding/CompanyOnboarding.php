<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Services\Accounting\PucProvisioner;
use App\Services\Accounting\TaxesProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador del onboarding inicial de una empresa. Llama a todos los
 * provisioners en el orden correcto dentro de una sola transacción para
 * que la creación sea atómica:
 *
 *   1. PUC (cuentas contables) — primero porque taxes y métodos de pago
 *      dependen de cuentas resueltas por código.
 *   2. Impuestos — dependen de cuentas (240805/240810/2365/2367/2370).
 *   3. Monedas — independiente.
 *   4. Métodos de pago — dependen de cuentas (110505/1110%).
 *   5. Sede principal — independiente.
 *   6. Consumidor Final — independiente.
 *   7. Plantillas de impresión — independiente.
 *
 * Todos los provisioners son idempotentes: bootstrap puede llamarse en
 * empresa nueva o en una existente para llenar lo que falte sin pisar
 * lo que el usuario ya configuró.
 *
 * Devuelve un mapa con cuántos registros creó cada provisioner para
 * que el comando artisan pueda mostrar un resumen útil.
 *
 * @return array{puc:int, taxes:int, currencies:int, payment_methods:int, location:int, consumer_final:int, invoice_templates:int}
 */
class CompanyOnboarding
{
    public function bootstrap(Company $company): array
    {
        $summary = [
            'puc' => 0,
            'taxes' => 0,
            'currencies' => 0,
            'payment_methods' => 0,
            'location' => 0,
            'consumer_final' => 0,
            'invoice_templates' => 0,
        ];

        DB::transaction(function () use ($company, &$summary) {
            $summary['puc'] = app(PucProvisioner::class)->provision($company);
            $summary['taxes'] = app(TaxesProvisioner::class)->provision($company);
            $summary['currencies'] = app(CurrencyProvisioner::class)->provision($company);
            $summary['payment_methods'] = app(PaymentMethodProvisioner::class)->provision($company);
            $summary['location'] = app(DefaultLocationProvisioner::class)->provision($company);
            $summary['consumer_final'] = app(ConsumerFinalProvisioner::class)->provision($company);
            $summary['invoice_templates'] = app(InvoiceTemplateProvisioner::class)->provision($company);
        });

        Log::info('[CompanyOnboarding] empresa provisionada', [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'summary' => $summary,
        ]);

        return $summary;
    }
}
