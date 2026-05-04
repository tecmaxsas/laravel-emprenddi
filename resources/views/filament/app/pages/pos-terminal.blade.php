<x-filament-panels::page>
    @php
        $totals = $this->totals();
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        {{-- ============================================================ --}}
        {{-- COLUMNA IZQUIERDA — Búsqueda + Carrito                       --}}
        {{-- ============================================================ --}}
        <div class="lg:col-span-7 space-y-3">
            {{-- Buscador --}}
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.250ms="productSearch"
                    placeholder="Escanea código de barras o busca producto por código/nombre…"
                    autofocus
                    class="w-full px-4 py-3 text-base rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
                />

                @if (!empty($searchResults))
                    <div class="absolute z-30 mt-1 w-full max-h-80 overflow-y-auto rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xl">
                        @foreach ($searchResults as $r)
                            <button
                                type="button"
                                wire:click="addProductToCart({{ $r['id'] }})"
                                class="w-full flex items-center justify-between gap-3 px-4 py-2 text-left hover:bg-primary-50 dark:hover:bg-gray-800 border-b border-gray-100 dark:border-gray-800 last:border-b-0"
                            >
                                <div class="min-w-0">
                                    <div class="font-mono text-xs text-gray-500 dark:text-gray-400 truncate">{{ $r['code'] }} @if ($r['barcode']) · {{ $r['barcode'] }} @endif</div>
                                    <div class="text-sm font-medium truncate">{{ $r['name'] }}</div>
                                </div>
                                <div class="text-sm font-semibold text-primary-600 dark:text-primary-400 whitespace-nowrap">
                                    ${{ number_format($r['price'], 0, ',', '.') }}
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Carrito --}}
            <div class="rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="px-4 py-2 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between bg-gray-50 dark:bg-gray-950">
                    <h3 class="text-sm font-semibold">Carrito ({{ count($cart) }} {{ count($cart) === 1 ? 'línea' : 'líneas' }})</h3>
                    @if (!empty($cart))
                        <button type="button" wire:click="resetCart" wire:confirm="¿Vaciar carrito?"
                                class="text-xs text-red-600 dark:text-red-400 hover:underline">
                            Vaciar
                        </button>
                    @endif
                </div>

                @if (empty($cart))
                    <div class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                        Aún no has agregado productos. Busca arriba.
                    </div>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-800 max-h-[60vh] overflow-y-auto">
                        @foreach ($cart as $i => $line)
                            <div class="px-4 py-3 grid grid-cols-12 gap-2 items-center">
                                {{-- Producto + descripción --}}
                                <div class="col-span-12 md:col-span-5 min-w-0">
                                    <div class="font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $line['code'] ?? '—' }}</div>
                                    <div class="text-sm font-medium truncate">{{ $line['description'] }}</div>
                                </div>

                                {{-- Qty con +/- --}}
                                <div class="col-span-4 md:col-span-2 flex items-center gap-1">
                                    <button type="button" wire:click="decLine({{ $i }})"
                                            class="w-7 h-7 rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-sm font-bold">−</button>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        wire:model.live.blur="cart.{{ $i }}.quantity"
                                        class="w-14 text-center text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-1 py-1"
                                    />
                                    <button type="button" wire:click="incLine({{ $i }})"
                                            class="w-7 h-7 rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-sm font-bold">+</button>
                                </div>

                                {{-- Precio unitario --}}
                                <div class="col-span-4 md:col-span-2">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        wire:model.live.blur="cart.{{ $i }}.unit_price"
                                        class="w-full text-right text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1"
                                    />
                                    <div class="text-[10px] text-gray-500 text-right">precio</div>
                                </div>

                                {{-- Descuento % --}}
                                <div class="col-span-2 md:col-span-1">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        wire:model.live.blur="cart.{{ $i }}.discount_percentage"
                                        class="w-full text-right text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1"
                                    />
                                    <div class="text-[10px] text-gray-500 text-right">% dto</div>
                                </div>

                                {{-- Total línea + remover --}}
                                <div class="col-span-2 md:col-span-2 flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold whitespace-nowrap">
                                        ${{ number_format($line['total'] ?? 0, 0, ',', '.') }}
                                    </span>
                                    <button type="button" wire:click="removeLine({{ $i }})"
                                            class="text-red-500 hover:text-red-700 text-lg" title="Eliminar línea">
                                        ×
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Subtotales del carrito --}}
                    <div class="px-4 py-3 bg-gray-50 dark:bg-gray-950 border-t border-gray-200 dark:border-gray-800 grid grid-cols-3 gap-3 text-sm">
                        <div>
                            <div class="text-xs text-gray-500">Subtotal</div>
                            <div class="font-semibold">${{ number_format($totals['subtotal'], 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Descuento</div>
                            <div class="font-semibold">${{ number_format($totals['discount'], 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">IVA</div>
                            <div class="font-semibold">${{ number_format($totals['tax'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- COLUMNA DERECHA — Cliente, Pagos, Total, Acción              --}}
        {{-- ============================================================ --}}
        <div class="lg:col-span-5 space-y-3">
            {{-- Header info --}}
            <div class="rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-3">
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Sede</div>
                        <div class="font-medium">{{ $this->locationName }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Cajero</div>
                        <div class="font-medium">{{ auth()->user()->name ?: auth()->user()->email }}</div>
                    </div>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-800 pt-3 flex items-center justify-between">
                    <div class="min-w-0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Cliente</div>
                        <div class="text-sm font-medium truncate">{{ $this->customerName }}</div>
                    </div>
                    <button type="button" wire:click="$set('showCustomerModal', true)"
                            class="text-xs px-3 py-1.5 rounded-md bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700">
                        Cambiar
                    </button>
                </div>
            </div>

            {{-- Total --}}
            <div class="rounded-xl border-2 border-primary-500 bg-primary-50 dark:bg-primary-950 p-4">
                <div class="text-xs text-primary-700 dark:text-primary-300 font-semibold uppercase">Total a pagar</div>
                <div class="text-3xl font-bold text-primary-900 dark:text-primary-100">
                    ${{ number_format($totals['total'], 0, ',', '.') }}
                </div>
            </div>

            {{-- Pagos --}}
            <div class="rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-3">
                <h3 class="text-sm font-semibold">Pagos</h3>

                {{-- Botones rápidos --}}
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->quickPayMethods as $method => $label)
                        <button type="button" wire:click="quickPay('{{ $method }}')"
                                class="px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 hover:bg-primary-50 dark:hover:bg-gray-800 hover:border-primary-400 transition">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- Líneas de pago --}}
                @if (!empty($payments))
                    <div class="space-y-2 pt-2 border-t border-gray-200 dark:border-gray-800">
                        @foreach ($payments as $i => $p)
                            <div class="grid grid-cols-12 gap-2 items-center">
                                <select
                                    wire:model.live="payments.{{ $i }}.payment_method"
                                    class="col-span-5 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1.5"
                                >
                                    @foreach ($this->paymentMethods as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>

                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    wire:model.live.blur="payments.{{ $i }}.amount"
                                    class="col-span-5 text-right text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1.5"
                                />

                                <button type="button" wire:click="removePayment({{ $i }})"
                                        class="col-span-2 text-red-500 hover:text-red-700 text-lg" title="Quitar pago">
                                    ×
                                </button>

                                @if (in_array($p['payment_method'] ?? '', ['bank_transfer', 'check', 'electronic'], true))
                                    <input
                                        type="text"
                                        placeholder="Referencia / Voucher"
                                        wire:model.blur="payments.{{ $i }}.reference"
                                        class="col-span-12 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1"
                                    />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Resumen pagado / vuelto --}}
                <div class="grid grid-cols-3 gap-2 text-center text-xs pt-2 border-t border-gray-200 dark:border-gray-800">
                    <div>
                        <div class="text-gray-500">Pagado</div>
                        <div class="font-semibold">${{ number_format($totals['paid'], 0, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Falta</div>
                        <div class="font-semibold {{ $totals['remaining'] > 0 ? 'text-amber-600' : '' }}">
                            ${{ number_format($totals['remaining'], 0, ',', '.') }}
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-500">Vuelto</div>
                        <div class="font-semibold {{ $totals['change'] > 0 ? 'text-green-600' : '' }}">
                            ${{ number_format($totals['change'], 0, ',', '.') }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Acción --}}
            <button type="button"
                    wire:click="processSale"
                    wire:loading.attr="disabled"
                    @disabled(empty($cart) || $totals['remaining'] > 0.01)
                    class="w-full py-4 rounded-xl bg-green-600 hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white text-lg font-bold uppercase tracking-wider transition">
                <span wire:loading.remove wire:target="processSale">Procesar Venta</span>
                <span wire:loading wire:target="processSale">Procesando…</span>
            </button>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- MODAL — Cambiar / crear cliente                                   --}}
    {{-- ================================================================ --}}
    @if ($showCustomerModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
             wire:click.self="$set('showCustomerModal', false)">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4">
                <h2 class="text-lg font-semibold">Cliente de la venta</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Crea un cliente nuevo o usa "Consumidor Final" para venta sin datos.</p>

                <div>
                    <label class="text-xs font-medium">Nombre / razón social</label>
                    <input type="text" wire:model="newCustomerName"
                           class="w-full mt-1 px-3 py-2 text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900" />
                </div>

                <div>
                    <label class="text-xs font-medium">Documento (CC/NIT)</label>
                    <input type="text" wire:model="newCustomerDocument"
                           class="w-full mt-1 px-3 py-2 text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('showCustomerModal', false)"
                            class="px-4 py-2 text-sm rounded bg-gray-200 dark:bg-gray-800 hover:bg-gray-300 dark:hover:bg-gray-700">
                        Cancelar
                    </button>
                    <button type="button" wire:click="createQuickCustomer"
                            class="px-4 py-2 text-sm rounded bg-primary-600 hover:bg-primary-700 text-white">
                        Crear y usar
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
