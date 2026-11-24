{{-- PDF del consolidado mensual de pendientes, por cliente.

     Vertical y agrupado: este formato es el que se le enseña a alguien en
     una reunión, no el que se filtra en una hoja de cálculo. Para eso está
     el Excel, que sí lleva una fila por referencia. --}}
@php
    $fmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $cant = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $totalValor = $consolidado->sum('valor');
    $totalUnidades = $consolidado->sum('unidades');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Consolidado de pendientes</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { font-size: 10px; color: #555; margin-bottom: 10px; }
        .empresa { font-size: 11px; font-weight: bold; }
        .resumen { margin-bottom: 10px; padding: 6px 8px; background: #f4f6f9; border: 1px solid #d6dbe3; }
        .cliente { margin-bottom: 10px; page-break-inside: avoid; }
        .cliente-head { background: #e9edf2; border: 1px solid #b8c0cc; padding: 4px 7px; font-weight: bold; }
        .cliente-total { float: right; color: #a15c00; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f4f6f9; border: 1px solid #d6dbe3; padding: 3px 6px; text-align: left; font-size: 8px; }
        td { border: 1px solid #e3e7ec; padding: 3px 6px; }
        .r { text-align: right; }
        .b { font-weight: bold; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .small { font-size: 7.5px; color: #666; }
        .vacio { padding: 22px; text-align: center; color: #666; border: 1px dashed #bbb; }
    </style>
</head>
<body>
    <div class="empresa">{{ $company?->name }}</div>
    <h1>Consolidado de pendientes por cliente</h1>
    <div class="sub">{{ $mes }} · generado {{ now()->format('Y-m-d H:i') }}</div>

    @if ($consolidado->isEmpty())
        <div class="vacio">No quedó nada pendiente por entregar en {{ $mes }}.</div>
    @else
        <div class="resumen">
            <strong>{{ $consolidado->count() }}</strong> cliente(s) con pendientes ·
            <strong>{{ $cant($totalUnidades) }}</strong> unidades ·
            <strong>{{ $fmt($totalValor) }}</strong> sin entregar
        </div>

        @foreach ($consolidado as $cliente)
            <div class="cliente">
                <div class="cliente-head">
                    <span class="cliente-total">{{ $cant($cliente['unidades']) }} und · {{ $fmt($cliente['valor']) }}</span>
                    {{ $cliente['cliente'] }}
                    @if ($cliente['nit'])
                        <span class="small">— NIT {{ $cliente['nit'] }}</span>
                    @endif
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:14%">Código</th>
                            <th>Referencia</th>
                            <th class="r" style="width:12%">Pendiente</th>
                            <th class="r" style="width:16%">Valor</th>
                            <th style="width:22%">Pedidos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cliente['lineas'] as $linea)
                            <tr>
                                <td class="mono">{{ $linea['codigo'] ?: '—' }}</td>
                                <td>{{ $linea['referencia'] }}</td>
                                <td class="r b">{{ $cant($linea['pendiente']) }}</td>
                                <td class="r b">{{ $fmt($linea['valor']) }}</td>
                                <td class="mono small">{{ implode(', ', $linea['pedidos']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</body>
</html>
