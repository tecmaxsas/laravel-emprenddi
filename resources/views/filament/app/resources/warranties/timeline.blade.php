{{-- Timeline simple de eventos de una garantía.
     $getRecord() viene del ViewEntry de Filament 3. --}}
@php
    use App\Models\Warranty;
    use App\Models\WarrantyEvent;

    $warranty = $getRecord();
    $events = $warranty->events()->with('user')->get();

    $iconFor = fn (WarrantyEvent $e) => match ($e->event_type) {
        WarrantyEvent::TYPE_CREATED => '✦',
        WarrantyEvent::TYPE_STATUS_CHANGE => '→',
        WarrantyEvent::TYPE_COMMENT => '💬',
        WarrantyEvent::TYPE_ASSIGNED => '👤',
        WarrantyEvent::TYPE_ATTACHMENT => '📎',
        default => '·',
    };
@endphp

<style>
    .wtl { padding-left: 22px; border-left: 2px solid #e5e7eb; }
    :is(.dark) .wtl { border-left-color: #1f2937; }
    .wtl-item { position: relative; padding: 0 0 18px 0; }
    .wtl-item::before {
        content: ""; position: absolute; left: -29px; top: 4px;
        width: 14px; height: 14px; border-radius: 50%;
        background: #fff; border: 2px solid #6366f1;
    }
    :is(.dark) .wtl-item::before { background: #1e293b; }
    .wtl-head {
        display: flex; gap: 8px; align-items: baseline;
        font-size: 13px; color: #374151; font-weight: 600;
    }
    :is(.dark) .wtl-head { color: #e5e7eb; }
    .wtl-icon { font-size: 14px; }
    .wtl-time {
        margin-left: auto; font-size: 11px; color: #9ca3af; font-weight: 400;
    }
    .wtl-body { font-size: 12.5px; color: #6b7280; margin-top: 4px; line-height: 1.55; }
    :is(.dark) .wtl-body { color: #9ca3af; }
    .wtl-tag {
        display: inline-block; padding: 1px 7px; border-radius: 4px;
        background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 600;
    }
    :is(.dark) .wtl-tag { background: #312e81; color: #c7d2fe; }
</style>

@if ($events->isEmpty())
    <div style="font-size:13px; color:#9ca3af;">Aún no hay eventos.</div>
@else
    <div class="wtl">
        @foreach ($events as $event)
            <div class="wtl-item">
                <div class="wtl-head">
                    <span class="wtl-icon">{!! $iconFor($event) !!}</span>
                    <span>
                        @switch ($event->event_type)
                            @case (WarrantyEvent::TYPE_CREATED)
                                Ticket creado
                                @break
                            @case (WarrantyEvent::TYPE_STATUS_CHANGE)
                                Estado:
                                <span class="wtl-tag">{{ Warranty::STATUSES[$event->from_status] ?? $event->from_status }}</span>
                                →
                                <span class="wtl-tag">{{ Warranty::STATUSES[$event->to_status] ?? $event->to_status }}</span>
                                @break
                            @case (WarrantyEvent::TYPE_ASSIGNED)
                                Técnico asignado
                                @break
                            @case (WarrantyEvent::TYPE_COMMENT)
                                Comentario
                                @break
                            @default
                                {{ $event->event_type }}
                        @endswitch
                    </span>
                    @if ($event->user)
                        <span style="color:#9ca3af; font-size:11px;">por {{ $event->user->name }}</span>
                    @endif
                    <span class="wtl-time">{{ $event->created_at?->format('Y-m-d H:i') }}</span>
                </div>
                @if ($event->comment)
                    <div class="wtl-body">{{ $event->comment }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif
