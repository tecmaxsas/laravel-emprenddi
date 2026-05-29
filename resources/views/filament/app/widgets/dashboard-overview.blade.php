<x-filament-widgets::widget>
<style>
    .ed-dash { display:flex; flex-direction:column; gap:18px; }
    .ed-dash * { box-sizing:border-box; }

    /* ---------- Hero ---------- */
    .ed-hero {
        position:relative; overflow:hidden; border-radius:18px; padding:26px 28px;
        background:linear-gradient(125deg,#4f46e5 0%,#7c3aed 48%,#2563eb 100%);
        color:#fff; box-shadow:0 14px 32px -12px rgba(79,70,229,.55);
        animation:edFadeUp .5s ease both;
    }
    .ed-hero::after {
        content:""; position:absolute; right:-60px; top:-60px; width:240px; height:240px;
        background:radial-gradient(circle,rgba(255,255,255,.20),transparent 70%); border-radius:50%;
    }
    .ed-hero::before {
        content:""; position:absolute; right:70px; bottom:-90px; width:200px; height:200px;
        background:radial-gradient(circle,rgba(255,255,255,.12),transparent 70%); border-radius:50%;
    }
    .ed-hero-greet { font-size:13px; font-weight:600; color:rgba(255,255,255,.78); letter-spacing:.02em; }
    .ed-hero-name { font-size:clamp(1.5rem,3.4vw,2rem); font-weight:800; margin:2px 0 6px; line-height:1.15; }
    .ed-hero-meta { font-size:12.5px; color:rgba(255,255,255,.72); display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .ed-chip { background:rgba(255,255,255,.18); padding:3px 11px; border-radius:999px; font-weight:600; backdrop-filter:blur(4px); }

    /* ---------- KPI grid ---------- */
    .ed-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:14px; }
    .ed-kpi {
        position:relative; overflow:hidden; background:#fff; border:1px solid #e9edf5;
        border-radius:15px; padding:18px 18px 16px; cursor:default;
        box-shadow:0 4px 14px -8px rgba(15,23,42,.25);
        transition:transform .18s ease, box-shadow .18s ease;
        animation:edFadeUp .5s ease both;
    }
    .ed-kpi:hover { transform:translateY(-5px); box-shadow:0 18px 30px -14px rgba(15,23,42,.4); }
    .ed-kpi-top { height:4px; position:absolute; top:0; left:0; right:0; }
    .ed-kpi-ic {
        width:42px; height:42px; border-radius:11px; display:flex; align-items:center;
        justify-content:center; margin-bottom:12px;
    }
    .ed-kpi-ic svg { width:22px; height:22px; }
    .ed-kpi-val { font-size:1.55rem; font-weight:800; color:#0f172a; line-height:1.1; letter-spacing:-.01em; }
    .ed-kpi-label { font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-top:5px; }
    .ed-kpi-sub { font-size:12px; color:#94a3b8; margin-top:7px; display:flex; align-items:center; gap:5px; }
    .ed-delta { font-weight:700; padding:2px 8px; border-radius:999px; font-size:11px; }
    .ed-delta.up { background:#dcfce7; color:#15803d; }
    .ed-delta.down { background:#fee2e2; color:#b91c1c; }

    /* ---------- Cards / panels ---------- */
    .ed-card {
        background:#fff; border:1px solid #e9edf5; border-radius:16px; padding:20px 22px;
        box-shadow:0 4px 14px -8px rgba(15,23,42,.22); animation:edFadeUp .5s ease both;
    }
    .ed-card-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .ed-card-title { font-size:15px; font-weight:800; color:#0f172a; }
    .ed-card-hint { font-size:12px; color:#94a3b8; }
    .ed-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

    /* ---------- Bar chart ---------- */
    .ed-chart { display:flex; align-items:flex-end; gap:6px; height:170px; padding-top:18px; }
    .ed-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:7px; min-width:0; }
    .ed-bar-wrap { width:100%; height:140px; display:flex; align-items:flex-end; justify-content:center; position:relative; }
    .ed-bar {
        width:72%; max-width:34px; min-height:3px; border-radius:7px 7px 3px 3px;
        background:linear-gradient(180deg,#6366f1,#4f46e5);
        transform-origin:bottom; animation:edGrow .7s cubic-bezier(.22,1,.36,1) both;
    }
    .ed-bar-col:hover .ed-bar { background:linear-gradient(180deg,#818cf8,#6366f1); }
    .ed-bar-tip {
        position:absolute; bottom:calc(100% + 4px); left:50%; transform:translateX(-50%);
        background:#0f172a; color:#fff; font-size:10.5px; font-weight:700; padding:3px 7px;
        border-radius:6px; white-space:nowrap; opacity:0; transition:opacity .15s ease; pointer-events:none;
    }
    .ed-bar-col:hover .ed-bar-tip { opacity:1; }
    .ed-bar-x { font-size:10px; color:#94a3b8; font-weight:600; white-space:nowrap; }
    .ed-bar-x.today { color:#4f46e5; font-weight:800; }

    /* ---------- Mini stats (restaurante / nómina) ---------- */
    .ed-mini { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid #f1f5f9; }
    .ed-mini:last-child { border-bottom:0; }
    .ed-mini-ic { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .ed-mini-ic svg { width:19px; height:19px; }
    .ed-mini-label { font-size:12.5px; color:#64748b; font-weight:600; }
    .ed-mini-val { margin-left:auto; font-size:15px; font-weight:800; color:#0f172a; }

    /* ---------- Activity table ---------- */
    .ed-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ed-table th { text-align:left; padding:8px 10px; font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em; border-bottom:2px solid #eef2f7; }
    .ed-table td { padding:9px 10px; border-bottom:1px solid #f4f6fa; color:#334155; }
    .ed-table tr:last-child td { border-bottom:0; }
    .ed-table tr { transition:background .12s ease; }
    .ed-table tbody tr:hover { background:#f8fafc; }
    .ed-pill { font-size:10px; font-weight:700; padding:2px 9px; border-radius:999px; }
    .ed-link { color:#4f46e5; text-decoration:none; font-weight:700; font-family:ui-monospace,monospace; }
    .ed-empty { padding:34px; text-align:center; color:#94a3b8; font-size:13px; }

    /* ---------- Animations ---------- */
    @keyframes edFadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
    @keyframes edGrow { from { transform:scaleY(0); } to { transform:scaleY(1); } }

    /* ---------- Dark mode ---------- */
    .dark .ed-kpi, .dark .ed-card { background:#1e293b; border-color:#334155; box-shadow:0 4px 16px -8px rgba(0,0,0,.6); }
    .dark .ed-kpi-val, .dark .ed-card-title, .dark .ed-mini-val { color:#f1f5f9; }
    .dark .ed-kpi-label { color:#94a3b8; }
    .dark .ed-kpi-sub, .dark .ed-card-hint, .dark .ed-bar-x, .dark .ed-mini-label { color:#94a3b8; }
    .dark .ed-mini { border-color:#334155; }
    .dark .ed-table th { color:#94a3b8; border-color:#334155; }
    .dark .ed-table td { color:#cbd5e1; border-color:#293548; }
    .dark .ed-table tbody tr:hover { background:#273449; }
    .dark .ed-link { color:#a5b4fc; }
    .dark .ed-delta.up { background:rgba(34,197,94,.18); color:#4ade80; }
    .dark .ed-delta.down { background:rgba(239,68,68,.18); color:#f87171; }

    @media (max-width:640px) {
        .ed-grid-2 { grid-template-columns:1fr; }
        .ed-bar-x { font-size:8px; }
    }
</style>

@php
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
    $fmt = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');

    // Tarjetas KPI según permisos.
    $kpis = [];
    if ($sales) {
        $kpis[] = ['label' => 'Ventas del mes', 'value' => $fmt($sales['month']), 'icon' => 'heroicon-o-chart-bar',
            'color' => '#10b981', 'sub' => $sales['invoices_month'].' facturas posteadas'];
        $kpis[] = ['label' => 'Por cobrar', 'value' => $fmt($sales['receivables']), 'icon' => 'heroicon-o-arrow-down-circle',
            'color' => '#f59e0b', 'sub' => $sales['receivables'] > 0 ? 'Cartera pendiente' : 'Sin cartera ✓'];
    }
    if ($purchases) {
        $kpis[] = ['label' => 'Compras del mes', 'value' => $fmt($purchases['month']), 'icon' => 'heroicon-o-shopping-bag',
            'color' => '#0ea5e9', 'sub' => 'Facturas y doc. soporte'];
        $kpis[] = ['label' => 'Por pagar', 'value' => $fmt($purchases['payables']), 'icon' => 'heroicon-o-arrow-up-circle',
            'color' => '#f43f5e', 'sub' => $purchases['due_soon'] > 0 ? $fmt($purchases['due_soon']).' vence en 7 días' : 'Sin vencimientos cercanos'];
    }
    if ($inventory) {
        $low = $inventory['low_stock'];
        $kpis[] = ['label' => 'Alertas de stock', 'value' => (string) $low, 'icon' => 'heroicon-o-archive-box',
            'color' => $low > 0 ? '#8b5cf6' : '#10b981', 'sub' => $low > 0 ? 'Productos en mínimo' : 'Inventario en regla ✓'];
    }
    if ($payroll && $payroll['employees'] !== null) {
        $kpis[] = ['label' => 'Empleados activos', 'value' => (string) $payroll['employees'], 'icon' => 'heroicon-o-identification',
            'color' => '#6366f1', 'sub' => 'En nómina'];
    }
@endphp

<div class="ed-dash">

    {{-- ===================== HERO ===================== --}}
    <div class="ed-hero">
        <div class="ed-hero-greet">{{ $greeting }} 👋</div>
        <div class="ed-hero-name">{{ $userName }}</div>
        <div class="ed-hero-meta">
            <span class="ed-chip">{{ $roleLabel }}</span>
            <span>{{ ucfirst(now()->locale('es')->isoFormat('dddd, D [de] MMMM')) }}</span>
        </div>
    </div>

    {{-- Las secciones se renderizan en el ORDEN y con la VISIBILIDAD que el
         usuario configuró en "Personalizar Escritorio". $visibleSections es
         una lista ordenada de keys; el @switch renderiza cada bloque. --}}
    @php
        $visibleSections = $visibleSections ?? ['kpis','sales_chart','restaurant','appointments','payroll','activity'];
    @endphp

    @foreach ($visibleSections as $section)
        @switch($section)

            {{-- ===================== KPIs ===================== --}}
            @case('kpis')
                @if (! empty($kpis))
                    <div class="ed-kpis">
                        @foreach ($kpis as $i => $k)
                            <div class="ed-kpi" style="animation-delay:{{ $i * 70 }}ms;">
                                <div class="ed-kpi-top" style="background:{{ $k['color'] }};"></div>
                                <div class="ed-kpi-ic" style="background:{{ $k['color'] }}1a; color:{{ $k['color'] }};">
                                    @svg($k['icon'])
                                </div>
                                <div class="ed-kpi-val">{{ $k['value'] }}</div>
                                <div class="ed-kpi-label">{{ $k['label'] }}</div>
                                <div class="ed-kpi-sub">{{ $k['sub'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @break

            {{-- ===================== GRÁFICA DE VENTAS ===================== --}}
            @case('sales_chart')
                @if ($sales)
                    @php
                        $maxSale = max(1, max(array_column($salesSeries, 'total')));
                        $todayKey = count($salesSeries) - 1;
                    @endphp
                    <div class="ed-card" style="animation-delay:120ms;">
                        <div class="ed-card-head">
                            <div>
                                <div class="ed-card-title">📈 Ventas — últimos 14 días</div>
                                <div class="ed-card-hint">Tendencia diaria de facturación</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:1.4rem; font-weight:800; color:#10b981;">{{ $fmt($sales['today']) }}</div>
                                <div class="ed-card-hint">
                                    Ventas de hoy ·
                                    <span class="ed-delta {{ $sales['delta'] >= 0 ? 'up' : 'down' }}">
                                        {{ $sales['delta'] >= 0 ? '↑' : '↓' }} {{ abs($sales['delta']) }}% vs ayer
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="ed-chart">
                            @foreach ($salesSeries as $i => $pt)
                                @php $h = $pt['total'] > 0 ? max(round($pt['total'] / $maxSale * 100), 5) : 0; @endphp
                                <div class="ed-bar-col">
                                    <div class="ed-bar-wrap">
                                        <span class="ed-bar-tip">{{ $fmt($pt['total']) }}</span>
                                        <div class="ed-bar" style="height:{{ $h }}%; animation-delay:{{ 150 + $i * 45 }}ms;
                                            @if ($i === $todayKey) background:linear-gradient(180deg,#34d399,#10b981); @endif"></div>
                                    </div>
                                    <span class="ed-bar-x {{ $i === $todayKey ? 'today' : '' }}">{{ $pt['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                @break

            {{-- ===================== RESTAURANTE ===================== --}}
            @case('restaurant')
                @if ($restaurant)
                    <div class="ed-card" style="animation-delay:170ms;">
                        <div class="ed-card-head"><div class="ed-card-title">🍽️ Restaurante hoy</div></div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#10b9811a; color:#10b981;">@svg('heroicon-o-table-cells')</div>
                            <div class="ed-mini-label">Mesas ocupadas</div>
                            <div class="ed-mini-val">{{ $restaurant['occupied'] }} / {{ $restaurant['total_tables'] }}</div>
                        </div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#0ea5e91a; color:#0ea5e9;">@svg('heroicon-o-clipboard-document-list')</div>
                            <div class="ed-mini-label">Órdenes abiertas</div>
                            <div class="ed-mini-val">{{ $restaurant['open_orders'] }}</div>
                        </div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#f59e0b1a; color:#f59e0b;">@svg('heroicon-o-truck')</div>
                            <div class="ed-mini-label">Domicilios activos</div>
                            <div class="ed-mini-val">{{ $restaurant['deliveries'] }}</div>
                        </div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#10b9811a; color:#10b981;">@svg('heroicon-o-banknotes')</div>
                            <div class="ed-mini-label">Ventas de hoy</div>
                            <div class="ed-mini-val">{{ $fmt($restaurant['today_sales']) }}</div>
                        </div>
                    </div>
                @endif
                @break

            {{-- ===================== CITAS ===================== --}}
            @case('appointments')
                @if ($appointments)
                    <div class="ed-card" style="animation-delay:185ms;">
                        <div class="ed-card-head"><div class="ed-card-title">📅 Citas hoy</div></div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#6366f11a; color:#6366f1;">@svg('heroicon-o-calendar-days')</div>
                            <div class="ed-mini-label">Agendadas hoy</div>
                            <div class="ed-mini-val">{{ $appointments['today'] }}</div>
                        </div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#f59e0b1a; color:#f59e0b;">@svg('heroicon-o-clock')</div>
                            <div class="ed-mini-label">Pendientes por atender</div>
                            <div class="ed-mini-val">{{ $appointments['pending'] }}</div>
                        </div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#10b9811a; color:#10b981;">@svg('heroicon-o-check-circle')</div>
                            <div class="ed-mini-label">Completadas</div>
                            <div class="ed-mini-val">{{ $appointments['completed'] }}</div>
                        </div>
                        @if ($appointments['next'])
                            <div class="ed-mini">
                                <div class="ed-mini-ic" style="background:#0ea5e91a; color:#0ea5e9;">@svg('heroicon-o-arrow-right-circle')</div>
                                <div class="ed-mini-label">
                                    Próxima · {{ $appointments['next']['client'] }}
                                    @if ($appointments['next']['service'])
                                        <span style="color:#9ca3af;">({{ $appointments['next']['service'] }})</span>
                                    @endif
                                </div>
                                <div class="ed-mini-val">{{ $appointments['next']['time'] }}</div>
                            </div>
                        @endif
                    </div>
                @endif
                @break

            {{-- ===================== NÓMINA ===================== --}}
            @case('payroll')
                @if ($payroll && $payroll['last_net'] !== null)
                    <div class="ed-card" style="animation-delay:200ms;">
                        <div class="ed-card-head"><div class="ed-card-title">👥 Nómina</div></div>
                        @if ($payroll['employees'] !== null)
                            <div class="ed-mini">
                                <div class="ed-mini-ic" style="background:#6366f11a; color:#6366f1;">@svg('heroicon-o-identification')</div>
                                <div class="ed-mini-label">Empleados activos</div>
                                <div class="ed-mini-val">{{ $payroll['employees'] }}</div>
                            </div>
                        @endif
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#10b9811a; color:#10b981;">@svg('heroicon-o-calculator')</div>
                            <div class="ed-mini-label">Última nómina · {{ $payroll['last_period'] ?? '—' }}</div>
                            <div class="ed-mini-val">{{ $fmt($payroll['last_net']) }}</div>
                        </div>
                        <div class="ed-mini">
                            <div class="ed-mini-ic" style="background:#f59e0b1a; color:#f59e0b;">@svg('heroicon-o-gift')</div>
                            <div class="ed-mini-label">Liquidaciones por pagar</div>
                            <div class="ed-mini-val">{{ $payroll['pending_settlements'] ?? 0 }}</div>
                        </div>
                    </div>
                @endif
                @break

            {{-- ===================== ACTIVIDAD RECIENTE ===================== --}}
            @case('activity')
                @if ($canSales || $canPurchases)
                    <div class="ed-card" style="animation-delay:240ms;">
                        <div class="ed-card-head">
                            <div>
                                <div class="ed-card-title">📋 Actividad reciente</div>
                                <div class="ed-card-hint">Últimas transacciones del negocio</div>
                            </div>
                        </div>
                        @if ($activity->isEmpty())
                            <div class="ed-empty">Sin actividad registrada todavía.</div>
                        @else
                            <div style="overflow-x:auto;">
                                <table class="ed-table">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th><th>Tipo</th><th>Documento</th>
                                            <th style="text-align:right;">Monto</th><th style="text-align:center;">Pago</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($activity as $a)
                                            @php
                                                $isSale = $a->kind === 'sale';
                                                $payStatus = $a->payment_status ?? 'pendiente';
                                                $payColors = [
                                                    'pagado' => ['#dcfce7', '#166534'],
                                                    'parcial' => ['#fef3c7', '#92400e'],
                                                    'pendiente' => ['#fee2e2', '#991b1b'],
                                                    'vencido' => ['#fee2e2', '#991b1b'],
                                                ];
                                                $pc = $payColors[$payStatus] ?? ['#e5e7eb', '#374151'];
                                                $url = ($isSale ? '/app/sale-invoices/' : '/app/purchase-invoices/').$a->id;
                                            @endphp
                                            <tr>
                                                <td style="font-family:ui-monospace,monospace; white-space:nowrap;">
                                                    {{ \Illuminate\Support\Carbon::parse($a->date)->format('d/m/Y') }}
                                                </td>
                                                <td>
                                                    <span style="font-weight:700; color:{{ $isSale ? '#10b981' : '#f43f5e' }};">
                                                        {{ $isSale ? '↗ Venta' : '↘ Compra' }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="{{ $url }}" class="ed-link">
                                                        {{ $a->prefix }}-{{ str_pad((string) $a->number, 6, '0', STR_PAD_LEFT) }}
                                                    </a>
                                                </td>
                                                <td style="text-align:right; font-weight:800;">
                                                    ${{ number_format((float) $a->total, 0, ',', '.') }}
                                                </td>
                                                <td style="text-align:center;">
                                                    <span class="ed-pill" style="background:{{ $pc[0] }}; color:{{ $pc[1] }};">
                                                        {{ ucfirst($payStatus) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
                @break

        @endswitch
    @endforeach

</div>
</x-filament-widgets::widget>
