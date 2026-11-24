{{-- PDF del informe de no entregas, línea por línea.

     Apaisado porque son catorce columnas: en vertical, la referencia y el
     motivo del cierre se parten en cuatro renglones y la tabla deja de
     leerse. --}}
@php
    $fmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $cant = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>No entregas</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; margin: 0; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .sub { font-size: 9px; color: #555; margin-bottom: 8px; }
        .empresa { font-size: 10px; font-weight: bold; margin-bottom: 1px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #e9edf2; border: 1px solid #b8c0cc; padding: 3px 4px; text-align: left; font-size: 7.5px; }
        td { border: 1px solid #d6dbe3; padding: 3px 4px; }
        .r { text-align: right; }
        .b { font-weight: bold; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.5px; }
        .resumen { margin-bottom: 8px; padding: 5px 7px; background: #f4f6f9; border: 1px solid #d6dbe3; font-size: 9px; }
        .cerrado { color: #a11; }
        .vacio { padding: 20px; text-align: center; color: #666; border: 1px dashed #bbb; }
    </style>
</head>
<body>
    <div class="empresa">{{ $company?->name }}</div>
    <h1>No entregas: referencias pendientes</h1>
    <div class="sub">{{ $subtitulo }} · generado {{ now()->format('Y-m-d H:i') }}</div>

    <div class="resumen">
        <strong>{{ $resumen['lineas'] }}</strong> línea(s) pendientes ·
        <strong>{{ $cant($resumen['unidades']) }}</strong> unidades ·
        <strong>{{ $fmt($resumen['valor']) }}</strong> sin entregar ·
        <strong>{{ $resumen['clientes'] }}</strong> cliente(s)
    </div>

    @if ($filas->isEmpty())
        <div class="vacio">No hay referencias pendientes en el período.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Fecha</th>
                    <th class="r">Días</th>
                    <th>Cliente</th>
                    <th>Código</th>
                    <th>Referencia</th>
                    <th class="r">Pedido</th>
                    <th class="r">Despach.</th>
                    <th class="r">Pendiente</th>
                    <th class="r">Valor</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($filas as $f)
                    <tr>
                        <td class="mono">{{ $f['pedido'] }}</td>
                        <td>{{ $f['fecha'] }}</td>
                        <td class="r">{{ $f['dias'] }}</td>
                        <td>{{ $f['cliente'] }}</td>
                        <td class="mono">{{ $f['codigo'] }}</td>
                        <td>{{ $f['referencia'] }}</td>
                        <td class="r">{{ $cant($f['pedido_cant']) }}</td>
                        <td class="r">{{ $cant($f['despachado']) }}</td>
                        <td class="r b">{{ $cant($f['pendiente']) }}</td>
                        <td class="r b">{{ $fmt($f['valor_pendiente']) }}</td>
                        <td class="{{ $f['cerrado'] ? 'cerrado' : '' }}">
                            {{ $f['estado'] }}
                            @if ($f['motivo_cierre'])
                                <br><span style="font-size:7px;">{{ $f['motivo_cierre'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
