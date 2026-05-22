<x-filament-panels::page>
    <form wire:submit.prevent="generate">
        {{ $this->form }}

        <div style="margin-top:14px;">
            <button type="submit"
                    wire:loading.attr="disabled"
                    style="padding:10px 22px; background:#2563eb; color:#fff; border:0; border-radius:8px; font-weight:700; cursor:pointer;">
                <span wire:loading.remove wire:target="generate">Generar reporte</span>
                <span wire:loading wire:target="generate">Generando…</span>
            </button>
        </div>
    </form>

    @if ($generated)
        @php
            $format = $this->currentFormat;
            $rows = $this->rows;
            $basis = $format['basis'] ?? 'movements';
            $grouped = collect($rows)->groupBy('concept_code');
            $grandTotal = collect($rows)->sum('amount');
            $partyCount = collect($rows)->pluck('third_party')->unique()->count();
        @endphp

        <div style="margin-top:20px; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#fff;">
            <div style="background:#1e293b; color:#fff; padding:14px 18px;">
                <div style="font-size:15px; font-weight:800;">
                    Formato {{ $this->filters['format_code'] ?? '' }} — {{ $format['name'] ?? '' }}
                </div>
                <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
                    Año gravable {{ $this->filters['year'] ?? '' }}
                    @if (! empty($rows))
                        · {{ $partyCount }} {{ $partyCount === 1 ? 'tercero' : 'terceros' }}
                        · {{ count($rows) }} registros
                    @endif
                </div>
            </div>

            @if ($basis === 'manual')
                <div style="padding:18px; font-size:13px; color:#92400e; background:#fffbeb;">
                    ⚠ Este formato se diligencia <strong>manualmente</strong> con base en las declaraciones
                    tributarias del año — no se deriva del libro contable. La captura asistida de este
                    formato se agregará en una iteración posterior.
                </div>
            @elseif (empty($rows))
                <div style="padding:22px 18px; font-size:13px; color:#475569; line-height:1.7;">
                    <strong>No hay datos para mostrar.</strong> Verificá que:
                    <ul style="margin:8px 0 0 18px;">
                        <li>Mapeaste cuentas a los conceptos de este formato en
                            <em>Contabilidad → Conceptos Exógena</em>.</li>
                        <li>Hay asientos <em>contabilizados</em> con tercero en el año seleccionado.</li>
                    </ul>
                </div>
            @else
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                    <label style="font-size:12px; font-weight:700; color:#475569;">
                        Tope cuantías menores
                    </label>
                    <input type="number" min="0" step="1000" wire:model="exportThreshold"
                           style="width:150px; padding:7px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#1e293b;" />
                    <span style="font-size:11px; color:#94a3b8; font-style:italic;">
                        Terceros bajo el tope se agrupan en "Cuantías menores" (0 = no agrupar)
                    </span>
                    <button type="button" wire:click="exportExcel"
                            wire:loading.attr="disabled"
                            style="margin-left:auto; padding:9px 18px; background:#16a34a; color:#fff; border:0; border-radius:7px; font-weight:700; font-size:13px; cursor:pointer;">
                        <span wire:loading.remove wire:target="exportExcel">⬇ Exportar a Excel</span>
                        <span wire:loading wire:target="exportExcel">Generando…</span>
                    </button>
                </div>

                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f1f5f9; color:#475569; text-align:left;">
                            <th style="padding:9px 14px; font-weight:700;">Tercero</th>
                            <th style="padding:9px 14px; font-weight:700;">Documento</th>
                            <th style="padding:9px 14px; font-weight:700; text-align:right;">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grouped as $conceptCode => $conceptRows)
                            @php
                                $conceptName = $conceptRows->first()['concept_name'] ?? $conceptCode;
                                $conceptTotal = $conceptRows->sum('amount');
                            @endphp
                            <tr style="background:#e0e7ff;">
                                <td colspan="2" style="padding:8px 14px; font-weight:800; color:#1e293b;">
                                    Concepto {{ $conceptCode }} — {{ $conceptName }}
                                </td>
                                <td style="padding:8px 14px; text-align:right; font-weight:800; color:#1e293b;">
                                    ${{ number_format($conceptTotal, 0, ',', '.') }}
                                </td>
                            </tr>
                            @foreach ($conceptRows as $row)
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:8px 14px; color:#1e293b;">{{ $row['third_party'] }}</td>
                                    <td style="padding:8px 14px; color:#64748b; font-family:monospace;">{{ $row['document_number'] ?: '—' }}</td>
                                    <td style="padding:8px 14px; text-align:right; color:#1e293b; font-weight:600;">
                                        ${{ number_format($row['amount'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background:#1e293b; color:#fff;">
                            <td colspan="2" style="padding:11px 14px; font-weight:800; text-transform:uppercase; letter-spacing:0.04em;">
                                Total formato {{ $this->filters['format_code'] ?? '' }}
                            </td>
                            <td style="padding:11px 14px; text-align:right; font-weight:800; font-size:15px;">
                                ${{ number_format($grandTotal, 0, ',', '.') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <div style="padding:12px 16px; font-size:11px; color:#94a3b8; border-top:1px solid #e5e7eb; line-height:1.6;">
                    El Excel exporta identificación del tercero + valor por concepto, listo para
                    revisar y cargar en el prevalidador DIAN. Verificá los datos del tercero
                    (tipo y número de documento, DV, nombres) antes de presentar.
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
