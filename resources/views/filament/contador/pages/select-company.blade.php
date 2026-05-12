<x-filament-panels::page>
    @php
        $companies = $this->getCompanies();
        $activeId = $this->getActiveCompanyId();
    @endphp

    @if ($companies->isEmpty())
        <div style="max-width: 640px; margin: 32px auto; padding: 32px; border-radius: 16px; background: rgb(254 243 199); border: 1px solid rgb(252 211 77); color: rgb(120 53 15);">
            <div style="display: flex; gap: 12px; align-items: flex-start;">
                <svg style="width: 24px; height: 24px; flex-shrink: 0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 6px;">Sin empresas asignadas</h3>
                    <p style="font-size: 14px; line-height: 1.5;">
                        Tu cuenta de contador está activa pero todavía no tienes ninguna empresa vinculada.
                        Contacta al equipo de Emprenddi (o al cliente cuya contabilidad llevas) para que te
                        vinculen las empresas correspondientes desde el panel administrativo.
                    </p>
                </div>
            </div>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            @foreach ($companies as $company)
                @php $isActive = $company->id === $activeId; @endphp
                <button
                    type="button"
                    wire:click="chooseCompany({{ $company->id }})"
                    style="
                        text-align: left;
                        padding: 20px;
                        border-radius: 12px;
                        border: 2px solid {{ $isActive ? 'rgb(16, 185, 129)' : 'rgb(229, 231, 235)' }};
                        background: {{ $isActive ? 'rgb(236, 253, 245)' : 'rgb(255, 255, 255)' }};
                        cursor: pointer;
                        transition: all 150ms;
                    "
                    class="dark:!bg-gray-900 dark:!border-gray-800 hover:!border-emerald-500"
                >
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, rgb(99, 102, 241), rgb(79, 70, 229)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                            {{ strtoupper(substr($company->legal_name ?? 'E', 0, 1)) }}
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ $company->legal_name }}
                            </div>
                            <div style="font-size: 12px; color: rgb(107, 114, 128); font-family: monospace;">
                                NIT {{ $company->document_number }}
                            </div>
                        </div>
                        @if ($isActive)
                            <span style="font-size: 11px; padding: 2px 8px; border-radius: 999px; background: rgb(16, 185, 129); color: white; font-weight: 600; text-transform: uppercase;">
                                Activa
                            </span>
                        @endif
                    </div>
                    @php
                        // Defensivo: el pivot puede venir como string o Carbon
                        // según si Laravel hidrata el Pivot custom o el default.
                        $granted = $company->pivot->granted_at ?? null;
                        $grantedLabel = $granted
                            ? \Illuminate\Support\Carbon::parse($granted)->diffForHumans()
                            : null;
                    @endphp
                    @if ($grantedLabel)
                        <div style="font-size: 12px; color: rgb(107, 114, 128);">
                            Vinculada {{ $grantedLabel }}
                        </div>
                    @endif
                </button>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
