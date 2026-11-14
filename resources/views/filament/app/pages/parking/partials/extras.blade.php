{{-- Buscador de productos y servicios, con la lista de lo agregado.

     Lo comparten la salida del vehiculo y la factura rapida: los dos escriben
     en $exitExtras, asi que el buscador, el carrito y los botones de cantidad
     son los mismos. Estaba escrito solo dentro del modal de salida, y copiarlo
     para la factura rapida habria dejado dos listas de productos que se
     separan a la primera correccion que alguien haga en una sola.

     Recibe $titulo: cambia segun donde se use —en la salida son «adicionales»
     al parqueo; en una venta de productos son la venta entera—. --}}
@php
    // Se leen de $this y no de variables del padre: el parcial se incluye
    // desde dos modales distintos y el de factura rapida no las define.
    // Depender del sitio donde te incluyen es como se rompio la primera vez.
    $titulo = $titulo ?? '🛍️ Servicios adicionales / productos';
    $extrasTotal = $this->extrasTotal;
    $searchResults = $this->productSearchResults;
    $fmt = $fmt ?? fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
@endphp
<div class="pt-extras">
    <div class="pt-extras-head">
        {{ $titulo }}
        @if (count($exitExtras) > 0)
            <span class="pt-extras-total">+ {{ $fmt($extrasTotal) }}</span>
        @endif
    </div>

    <div class="pt-extras-search">
        <input type="text" wire:model.live.debounce.250ms="extraSearch"
            placeholder="Buscar producto o servicio (mín. 2 letras)..." />
        @if ($searchResults->count() > 0)
            <div class="pt-extras-results">
                @foreach ($searchResults as $p)
                    <button type="button" wire:click="addExtra({{ $p->id }})" class="pt-extra-result">
                        <span class="pt-extra-result-name">
                            <strong>{{ $p->name }}</strong>
                            <span class="pt-extra-result-code">{{ $p->code }}</span>
                        </span>
                        <span class="pt-extra-result-price">{{ $fmt($p->default_sale_price) }}</span>
                    </button>
                @endforeach
            </div>
        @elseif (strlen(trim($extraSearch)) >= 2)
            <div class="pt-extras-empty">Sin coincidencias para "{{ $extraSearch }}"</div>
        @endif
    </div>

    @if (count($exitExtras) > 0)
        <div class="pt-extras-list">
            @foreach ($exitExtras as $i => $extra)
                <div class="pt-extra-row">
                    <div class="pt-extra-info">
                        <strong>{{ $extra['product_name'] }}</strong>
                        <span class="pt-extra-code">{{ $extra['product_code'] }} · {{ $fmt($extra['unit_price']) }} c/u</span>
                    </div>
                    <div class="pt-extra-qty">
                        <button type="button" wire:click="adjustExtraQty({{ $i }}, -1)">−</button>
                        <span>{{ $extra['quantity'] }}</span>
                        <button type="button" wire:click="adjustExtraQty({{ $i }}, 1)">+</button>
                    </div>
                    <div class="pt-extra-subtotal">{{ $fmt($extra['quantity'] * $extra['unit_price']) }}</div>
                    <button type="button" wire:click="removeExtra({{ $i }})" class="pt-extra-remove" title="Quitar">×</button>
                </div>
            @endforeach
        </div>
    @endif
</div>
