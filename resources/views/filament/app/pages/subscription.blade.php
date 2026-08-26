@php
    use App\Models\Subscription;

    $sub = $this->subscription;
    $plan = $this->plan;
    $dias = $this->daysLeft;
    $vigente = $this->isCurrent;

    $estados = [
        Subscription::STATUS_TRIAL => ['Periodo de prueba', 'info'],
        Subscription::STATUS_ACTIVE => ['Activa', 'success'],
        Subscription::STATUS_PAST_DUE => ['Pago pendiente', 'warning'],
        Subscription::STATUS_CANCELLED => ['Cancelada', 'danger'],
        Subscription::STATUS_EXPIRED => ['Vencida', 'danger'],
        Subscription::STATUS_SUSPENDED => ['Suspendida', 'danger'],
    ];
    [$estadoLabel, $estadoColor] = $estados[$sub?->status] ?? ['Sin suscripción', 'gray'];

    $ciclos = ['monthly' => 'Mensual', 'yearly' => 'Anual', 'quarterly' => 'Trimestral', 'lifetime' => 'Vitalicia'];

    // Aviso segun lo que falte para vencer.
    $aviso = null;
    if ($sub && $dias !== null) {
        if ($dias < 0)       $aviso = ['danger',  'Tu suscripción venció hace '.abs($dias).' día(s).'];
        elseif ($dias === 0) $aviso = ['danger',  'Tu suscripción vence hoy.'];
        elseif ($dias <= 7)  $aviso = ['warning', 'Tu suscripción vence en '.$dias.' día(s).'];
        elseif ($dias <= 30) $aviso = ['info',    'Tu suscripción vence en '.$dias.' días.'];
    }
@endphp

<x-filament-panels::page>

    @if (! $sub)
        <x-filament::section>
            <div class="text-sm" style="opacity:.75;">
                Esta empresa no tiene una suscripción registrada. Comunícate con soporte para activarla.
            </div>
        </x-filament::section>
    @else

        @if ($aviso)
            <x-filament::section>
                <div @class(['text-sm font-medium']) @style([
                    'color: rgb(var(--danger-600))' => $aviso[0] === 'danger',
                    'color: rgb(var(--warning-600))' => $aviso[0] === 'warning',
                    'color: rgb(var(--info-600))' => $aviso[0] === 'info',
                ])>
                    {{ $aviso[1] }}
                </div>
            </x-filament::section>
        @endif

        {{-- ---------------- Plan y vigencia ---------------- --}}
        <x-filament::section>
            <x-slot name="heading">Plan contratado</x-slot>

            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; margin-bottom:.75rem;">
                <span class="text-xl font-bold">{{ $plan?->name ?? 'Plan no disponible' }}</span>
                <x-filament::badge :color="$estadoColor">{{ $estadoLabel }}</x-filament::badge>
                @unless ($vigente)
                    <x-filament::badge color="gray">Sin vigencia</x-filament::badge>
                @endunless
            </div>

            @if ($plan?->description)
                <p class="text-sm" style="opacity:.75; margin-bottom:1rem;">{{ $plan->description }}</p>
            @endif

            <dl style="display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:1rem;">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide" style="opacity:.6;">Inicio</dt>
                    <dd class="text-sm font-semibold">{{ $sub->starts_at?->format('d/m/Y') ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide" style="opacity:.6;">Vencimiento</dt>
                    <dd class="text-sm font-semibold">{{ $sub->ends_at?->format('d/m/Y') ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide" style="opacity:.6;">Días restantes</dt>
                    <dd class="text-sm font-semibold">
                        @if ($dias === null)
                            —
                        @elseif ($dias < 0)
                            Vencida
                        @else
                            {{ $dias }}
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide" style="opacity:.6;">Ciclo</dt>
                    <dd class="text-sm font-semibold">
                        {{ $ciclos[$sub->billing_cycle ?? ''] ?? ($sub->billing_cycle ?: '—') }}
                    </dd>
                </div>

                @if ($sub->status === Subscription::STATUS_TRIAL && $plan?->trial_days)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide" style="opacity:.6;">Días de prueba</dt>
                        <dd class="text-sm font-semibold">{{ $plan->trial_days }}</dd>
                    </div>
                @endif

                @if ($sub->cancelled_at)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide" style="opacity:.6;">Cancelada el</dt>
                        <dd class="text-sm font-semibold">{{ $sub->cancelled_at->format('d/m/Y') }}</dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        {{-- ---------------- Funcionalidades ---------------- --}}
        @if ($this->features !== [])
            <x-filament::section>
                <x-slot name="heading">Incluido en tu plan</x-slot>

                <ul style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:.5rem;">
                    @foreach ($this->features as $feature)
                        <li style="display:flex; align-items:center; gap:.5rem;" class="text-sm">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 text-success-500" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{-- ---------------- Límites y uso ---------------- --}}
        @if ($this->limits !== [])
            <x-filament::section>
                <x-slot name="heading">Uso de tu plan</x-slot>
                <x-slot name="description">Consumo actual frente a lo que incluye el plan.</x-slot>

                <div style="display:flex; flex-direction:column; gap:1rem;">
                    @foreach ($this->limits as $limite)
                        @php
                            $pct = $limite['percent'] ?? 0;
                            $barra = $pct >= 90 ? '#ef4444' : ($pct >= 75 ? '#f59e0b' : '#22c55e');
                        @endphp
                        <div>
                            <div style="display:flex; justify-content:space-between; gap:1rem; margin-bottom:.3rem;">
                                <span class="text-sm font-medium">{{ $limite['label'] }}</span>
                                <span class="text-sm" style="opacity:.7; font-variant-numeric:tabular-nums;">
                                    {{ number_format($limite['current']) }} de {{ number_format($limite['limit']) }}
                                </span>
                            </div>
                            <div style="height:.4rem; border-radius:9999px; background:rgba(128,128,128,.2); overflow:hidden;">
                                <div style="height:100%; width:{{ $pct }}%; background:{{ $barra }}; border-radius:9999px;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <p class="text-xs" style="opacity:.6;">
            ¿Necesitas cambiar de plan o renovar? Escríbenos por el botón de soporte.
        </p>
    @endif

</x-filament-panels::page>
