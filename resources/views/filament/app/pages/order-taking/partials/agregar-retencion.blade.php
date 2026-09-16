{{--
    Agregar una retención que el cliente no tenía configurada.

    Hace falta más seguido de lo que parece: un pedido puntual que supera la base
    mínima de retefuente, o un cliente que acaba de volverse agente retenedor y
    todavía no está actualizado en su ficha. Antes había que salirse a editar el
    tercero y volver a empezar el pedido.

    El botón de restaurar va aquí y no solo cuando la lista queda vacía: quitar
    una de dos y no poder devolverla era el caso en que la gente se quedaba
    atascada.
--}}
@php
    $disponibles = $this->availableRetentions;
    $configuradas = $customer?->retentionTaxes()->where('is_active', true)->count() ?? 0;
@endphp

@if ($disponibles->isNotEmpty() || $configuradas > 0)
    <div style="margin-top:8px; display:flex; gap:6px; align-items:center;">
        @if ($disponibles->isNotEmpty())
            <select wire:model="retentionToAdd"
                    wire:change="addRetention($event.target.value)"
                    style="flex:1; min-width:0; padding:5px 6px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#0f172a; font-size:11.5px;">
                <option value="">+ Agregar retención…</option>
                @foreach ($disponibles as $t)
                    <option value="{{ $t->id }}">{{ $t->code }} · {{ rtrim(rtrim(number_format((float) $t->rate, 3, ',', '.'), '0'), ',') }}%</option>
                @endforeach
            </select>
        @endif

        @if ($configuradas > 0)
            <button type="button" wire:click="restoreRetentions"
                    title="Volver a las retenciones configuradas en el cliente"
                    style="padding:5px 8px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:6px; font-weight:700; font-size:11.5px; cursor:pointer; white-space:nowrap;">
                ↺ Las del cliente ({{ $configuradas }})
            </button>
        @endif
    </div>
@endif
