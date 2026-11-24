{{-- Consolidado mensual de pendientes, agrupado por cliente y referencia.

     Sin tabla de Filament a propósito: son dos niveles de agrupación y no
     hay un modelo por fila, así que montarlo con una tabla obligaría a
     inventar un registro intermedio solo para que el componente tuviera de
     dónde agarrarse. --}}
@php
    $consolidado = $this->consolidado;
    $totales = $this->totales;
    $fmt = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
    $cant = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
@endphp

<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="otp-toolbar">
        <a href="{{ $this->getExportUrl() }}" class="otp-btn otp-btn-excel" target="_blank" rel="noopener">
            📊 Descargar Excel
        </a>
        <a href="{{ $this->getPdfUrl() }}" class="otp-btn otp-btn-pdf" target="_blank" rel="noopener">
            📄 Descargar PDF
        </a>
    </div>

    <div class="otp-kpis">
        <div class="otp-kpi">
            <span class="otp-kpi-num">{{ $totales['clientes'] }}</span>
            <span class="otp-kpi-label">Clientes</span>
        </div>
        <div class="otp-kpi">
            <span class="otp-kpi-num">{{ $totales['referencias'] }}</span>
            <span class="otp-kpi-label">Referencias</span>
        </div>
        <div class="otp-kpi">
            <span class="otp-kpi-num">{{ $cant($totales['unidades']) }}</span>
            <span class="otp-kpi-label">Unidades</span>
        </div>
        <div class="otp-kpi otp-kpi-plata">
            <span class="otp-kpi-num">{{ $fmt($totales['valor']) }}</span>
            <span class="otp-kpi-label">Valor pendiente</span>
        </div>
    </div>

    @if ($consolidado->isEmpty())
        <div class="otp-vacio">
            No quedó nada pendiente por entregar en {{ $this->etiquetaDelMes() }}.
        </div>
    @else
        @foreach ($consolidado as $cliente)
            <div class="otp-cliente">
                <div class="otp-cliente-head">
                    <div>
                        <strong>{{ $cliente['cliente'] }}</strong>
                        @if ($cliente['nit'])
                            <span class="otp-nit">NIT {{ $cliente['nit'] }}</span>
                        @endif
                    </div>
                    <div class="otp-cliente-total">
                        {{ $cant($cliente['unidades']) }} und · {{ $fmt($cliente['valor']) }}
                    </div>
                </div>

                <table class="otp-tabla">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Referencia</th>
                            <th class="otp-r">Pendiente</th>
                            <th class="otp-r">Valor</th>
                            <th>Pedidos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cliente['lineas'] as $linea)
                            <tr>
                                <td class="otp-mono">{{ $linea['codigo'] ?: '—' }}</td>
                                <td>{{ $linea['referencia'] }}</td>
                                <td class="otp-r otp-bold">{{ $cant($linea['pendiente']) }}</td>
                                <td class="otp-r otp-bold">{{ $fmt($linea['valor']) }}</td>
                                <td class="otp-mono otp-small">{{ implode(', ', $linea['pedidos']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif

    <style>
        .otp-toolbar { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
        .otp-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; color:#fff; }
        .otp-btn-excel { background-color:#16a34a; }
        .otp-btn-pdf { background-color:#b91c1c; }

        .otp-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin:14px 0; }
        .otp-kpi { background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; flex-direction:column; }
        .otp-kpi-num { font-size:21px; font-weight:900; color:#0f172a; }
        .otp-kpi-label { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:700; }
        .otp-kpi-plata .otp-kpi-num { color:#b45309; }

        .otp-cliente { background-color:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:12px; overflow:hidden; }
        .otp-cliente-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 14px; background-color:#f1f5f9; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
        .otp-cliente-head strong { font-size:14px; color:#0f172a; }
        .otp-nit { font-size:11px; color:#64748b; margin-left:8px; }
        .otp-cliente-total { font-size:13px; font-weight:800; color:#b45309; }

        .otp-tabla { width:100%; border-collapse:collapse; font-size:13px; }
        .otp-tabla th { text-align:left; padding:7px 14px; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0; }
        .otp-tabla td { padding:7px 14px; border-bottom:1px solid #f1f5f9; color:#0f172a; }
        .otp-tabla tr:last-child td { border-bottom:0; }
        .otp-r { text-align:right; }
        .otp-bold { font-weight:700; }
        .otp-mono { font-family:ui-monospace, monospace; }
        .otp-small { font-size:11px; color:#64748b; }

        .otp-vacio { background-color:#fff; border:1px dashed #cbd5e1; border-radius:10px; padding:28px; text-align:center; color:#64748b; }

        /* Modo oscuro: sin esto el tema pinta el texto claro sobre estas
           tarjetas blancas y no se lee nada. */
        :is(.dark) .otp-kpi,
        :is(.dark) .otp-cliente,
        :is(.dark) .otp-vacio { background-color:#1f2937; border-color:#374151; }
        :is(.dark) .otp-cliente-head { background-color:#111827; border-color:#374151; }
        :is(.dark) .otp-kpi-num,
        :is(.dark) .otp-cliente-head strong,
        :is(.dark) .otp-tabla td { color:#f1f5f9; }
        :is(.dark) .otp-tabla td { border-color:#374151; }
    </style>
</x-filament-panels::page>
