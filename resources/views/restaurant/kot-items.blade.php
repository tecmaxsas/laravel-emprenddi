@php
    /** @var \App\Models\Restaurant\KitchenTicket $ticket */
    $byCourse = [];
    foreach ($ticket->items_snapshot ?? [] as $it) {
        $byCourse[(int) ($it['course'] ?? 1)][] = $it;
    }
    ksort($byCourse);
@endphp

<div class="fi-ta-text text-sm" style="display:flex; flex-direction:column; gap:1rem;">
    @forelse ($byCourse as $courseNum => $items)
        <div>
            <div class="font-semibold uppercase text-xs tracking-wide" style="opacity:.6; margin-bottom:.35rem;">
                {{ \App\Models\Restaurant\OrderItem::COURSES[$courseNum] ?? ('Curso '.$courseNum) }}
            </div>

            <div style="display:flex; flex-direction:column; gap:.5rem;">
                @foreach ($items as $item)
                    <div>
                        <div class="font-semibold">
                            {{ (int) ($item['quantity'] ?? 1) }}× {{ $item['description'] ?? '?' }}
                        </div>

                        @foreach (($item['modifiers'] ?? []) as $mod)
                            @php $modName = is_array($mod) ? ($mod['name'] ?? '') : (string) $mod; @endphp
                            @if ($modName)
                                <div style="padding-left:1rem; opacity:.75;">+ {{ $modName }}</div>
                            @endif
                        @endforeach

                        @if (! empty($item['note']))
                            <div style="padding-left:1rem;" class="font-semibold">Nota: {{ $item['note'] }}</div>
                        @endif

                        @if (! empty($item['split_tab']))
                            <div style="padding-left:1rem; opacity:.6;">({{ $item['split_tab'] }})</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div style="opacity:.6;">Esta comanda no tiene ítems registrados.</div>
    @endforelse
</div>
