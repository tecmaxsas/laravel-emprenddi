<x-filament-panels::page>
    {{-- Paso 1: descargar plantilla + subir --}}
    <div style="background:#eef2ff; border:1px solid #6366f1; border-radius:10px; padding:14px 16px; margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <div style="font-weight:800; color:#3730a3; font-size:14px;">📋 Paso 1 — Descarga la plantilla</div>
                <div style="font-size:12.5px; color:#4c1d95; margin-top:2px;">Incluye instrucciones, ejemplos y las hojas de referencia (categorías, impuestos, cuentas) con los códigos de tu empresa.</div>
            </div>
            <a href="{{ route('products.import.template') }}"
                style="background:#4f46e5; color:#fff; padding:9px 16px; border-radius:8px; text-decoration:none; font-weight:700; font-size:13px;">
                ⬇️ Descargar plantilla XLSX
            </a>
        </div>
    </div>

    <form wire:submit.prevent>{{ $this->form }}</form>

    <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
        <button type="button" wire:click="analyzeFile"
            style="padding:10px 18px; background:#0ea5e9; color:#fff; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
            🔍 Analizar archivo
        </button>
        @if ($preview)
            <button type="button" wire:click="resetPreview"
                style="padding:10px 14px; background:#e2e8f0; color:#1e293b; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
                Descartar preview
            </button>
        @endif
    </div>

    {{-- Preview --}}
    @if ($preview)
        @if (! empty($preview['fatal']))
            <div style="margin-top:14px; background:#fee2e2; color:#991b1b; border:1px solid #dc2626; padding:12px 14px; border-radius:8px; font-weight:600;">
                🚫 {{ $preview['fatal'] }}
            </div>
        @else
            @php $s = $preview['summary']; @endphp
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-top:14px;">
                <div style="background:#0f172a; color:#fff; border-radius:10px; padding:12px 14px;">
                    <div style="font-size:11px; opacity:.7; text-transform:uppercase; font-weight:700;">Total filas</div>
                    <div style="font-size:24px; font-weight:900;">{{ $s['total'] }}</div>
                </div>
                <div style="background:#16a34a; color:#fff; border-radius:10px; padding:12px 14px;">
                    <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">A crear</div>
                    <div style="font-size:24px; font-weight:900;">{{ $s['to_create'] }}</div>
                </div>
                <div style="background:#0ea5e9; color:#fff; border-radius:10px; padding:12px 14px;">
                    <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">A actualizar</div>
                    <div style="font-size:24px; font-weight:900;">{{ $s['to_update'] }}</div>
                </div>
                <div style="background:{{ $s['errors'] > 0 ? '#dc2626' : '#6b7280' }}; color:#fff; border-radius:10px; padding:12px 14px;">
                    <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Con errores</div>
                    <div style="font-size:24px; font-weight:900;">{{ $s['errors'] }}</div>
                </div>
            </div>

            @if ($s['errors'] > 0)
                <div style="margin-top:14px; background:#fef3c7; color:#78350f; border:1px solid #f59e0b; padding:10px 14px; border-radius:8px; font-size:13px;">
                    ⚠ Hay filas con errores. <strong>Corrige el archivo y vuelve a subirlo</strong> antes de confirmar. Ninguna fila se importará hasta que todas estén válidas.
                </div>
            @endif

            <div style="margin-top:14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:auto; max-height:500px;">
                <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
                    <thead style="background:#f3f4f6; position:sticky; top:0;">
                        <tr>
                            <th style="padding:8px 10px; text-align:left;">#</th>
                            <th style="padding:8px 10px; text-align:left;">Code</th>
                            <th style="padding:8px 10px; text-align:left;">Nombre</th>
                            <th style="padding:8px 10px; text-align:left;">Tipo</th>
                            <th style="padding:8px 10px; text-align:left;">Padre</th>
                            <th style="padding:8px 10px; text-align:right;">Precio</th>
                            <th style="padding:8px 10px; text-align:left;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $r)
                            @php
                                $hasErr = ! empty($r['errors']);
                                $bg = $hasErr ? '#fef2f2' : '#fff';
                            @endphp
                            <tr style="border-top:1px solid #f1f5f9; background:{{ $bg }};">
                                <td style="padding:6px 10px; font-family:ui-monospace, monospace;">{{ $r['row_number'] }}</td>
                                <td style="padding:6px 10px; font-family:ui-monospace, monospace; font-weight:700;">{{ $r['data']['code'] ?? '—' }}</td>
                                <td style="padding:6px 10px;">{{ $r['data']['name'] ?? '—' }}</td>
                                <td style="padding:6px 10px;">
                                    <span style="background:#e0e7ff; color:#3730a3; padding:1px 7px; border-radius:4px; font-size:11px; font-weight:700;">
                                        {{ $r['data']['type'] ?? '—' }}
                                    </span>
                                </td>
                                <td style="padding:6px 10px; font-family:ui-monospace, monospace; color:#6b7280;">{{ $r['data']['variation_of_code'] ?? '' }}</td>
                                <td style="padding:6px 10px; text-align:right;">
                                    @if (! empty($r['data']['sale_price']))
                                        $ {{ number_format((float) $r['data']['sale_price'], 0, ',', '.') }}
                                    @endif
                                </td>
                                <td style="padding:6px 10px;">
                                    @if ($hasErr)
                                        <div style="color:#991b1b; font-weight:600;">✕ Errores:</div>
                                        <ul style="margin:2px 0 0 12px; color:#7f1d1d; font-size:11.5px;">
                                            @foreach ($r['errors'] as $err)
                                                <li>{{ $err }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span style="color:#166534; font-weight:600;">✓ OK</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($preview['valid'])
                <div style="margin-top:14px; padding:14px; background:#dcfce7; border:1px solid #16a34a; border-radius:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="font-weight:600; color:#166534;">
                        ✓ Todas las filas están válidas. Puedes confirmar la importación.
                    </div>
                    <button type="button" wire:click="confirmImport"
                        onclick="return confirm('¿Confirmar importación de {{ $s['total'] }} productos?')"
                        style="padding:11px 22px; background:#16a34a; color:#fff; border:0; border-radius:8px; font-weight:800; font-size:14px; cursor:pointer;">
                        ✓ Confirmar importación
                    </button>
                </div>
            @endif
        @endif
    @endif

    {{-- Resultado --}}
    @if ($result)
        <div style="margin-top:14px; background:#f0fdf4; border:2px solid #16a34a; border-radius:10px; padding:14px 16px;">
            <div style="font-weight:800; color:#166534; font-size:14px; margin-bottom:6px;">✓ Importación completada</div>
            <div style="font-size:13px; color:#166534;">
                <strong>{{ $result['created'] }}</strong> creados ·
                <strong>{{ $result['updated'] }}</strong> actualizados
                @if (! empty($result['errors']))
                    · <span style="color:#991b1b;"><strong>{{ count($result['errors']) }}</strong> con error</span>
                @endif
            </div>
            @if (! empty($result['errors']))
                <div style="margin-top:10px; background:#fff; padding:10px 12px; border-radius:8px; max-height:200px; overflow:auto;">
                    <div style="font-weight:700; color:#991b1b; font-size:12.5px; margin-bottom:4px;">Errores:</div>
                    @foreach ($result['errors'] as $err)
                        <div style="font-size:12px; padding:3px 0; border-top:1px solid #fee2e2;">
                            Fila {{ $err['row_number'] }} — <code>{{ $err['code'] }}</code>: {{ $err['message'] }}
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
