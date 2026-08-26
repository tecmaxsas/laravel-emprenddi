<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlanLimitChecker;
use App\Support\CurrentCompany;
use Filament\Pages\Page;

/**
 * Suscripcion de la empresa: plan contratado, vigencia y limites de uso.
 *
 * Deliberadamente NO muestra dinero — ni el precio del plan ni lo pagado.
 * Esta pantalla la ve el cliente para saber que tiene contratado y hasta
 * cuando, no para consultar su facturacion con nosotros.
 */
class SubscriptionPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Suscripción';

    protected static ?string $title = 'Mi suscripción';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 130;

    protected static ?string $slug = 'subscription';

    protected static string $view = 'filament.app.pages.subscription';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('company.settings');
    }

    protected function company(): ?Company
    {
        return app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
    }

    /**
     * La suscripcion vigente; si no hay ninguna activa, la ultima que hubo,
     * para poder explicar por que el acceso esta limitado en vez de mostrar
     * la pantalla vacia.
     */
    public function getSubscriptionProperty(): ?Subscription
    {
        $company = $this->company();

        if (! $company) {
            return null;
        }

        return $company->activeSubscription()->with('plan')->first()
            ?? $company->subscriptions()->with('plan')->latest('ends_at')->first();
    }

    public function getPlanProperty(): ?Plan
    {
        return $this->subscription?->plan;
    }

    public function getIsCurrentProperty(): bool
    {
        $s = $this->subscription;

        return $s !== null
            && in_array($s->status, Subscription::ACTIVE_STATUSES, true)
            && $s->ends_at?->isFuture();
    }

    /** Dias que faltan para el vencimiento. Negativo si ya vencio. */
    public function getDaysLeftProperty(): ?int
    {
        $endsAt = $this->subscription?->ends_at;

        return $endsAt ? (int) now()->startOfDay()->diffInDays($endsAt->startOfDay(), false) : null;
    }

    /**
     * Funcionalidades incluidas en el plan, con su nombre legible.
     *
     * @return array<int, string>
     */
    public function getFeaturesProperty(): array
    {
        $incluidas = $this->plan?->features ?? [];

        return collect(Plan::FEATURE_KEYS)
            ->filter(fn ($label, $key) => in_array($key, $incluidas, true))
            ->values()
            ->all();
    }

    /**
     * Limites del plan con el consumo actual.
     *
     * @return array<int, array{label:string, current:int, limit:?int, percent:?float}>
     */
    public function getLimitsProperty(): array
    {
        $company = $this->company();

        if (! $company || ! $this->plan) {
            return [];
        }

        $checker = app(PlanLimitChecker::class);
        $filas = [];

        foreach (Plan::LIMIT_KEYS as $key => $label) {
            $limite = $this->plan->limit($key);

            if ($limite === null) {
                continue;   // el plan no restringe esto
            }

            $r = $checker->check($company, $key);
            $filas[] = [
                'label' => $label,
                'current' => (int) $r['current'],
                'limit' => $limite,
                'percent' => $limite > 0 ? min(100, round(($r['current'] / $limite) * 100, 1)) : null,
            ];
        }

        return $filas;
    }
}
