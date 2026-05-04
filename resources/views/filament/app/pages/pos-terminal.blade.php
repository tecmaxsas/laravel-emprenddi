<x-filament-panels::page>
    {{-- Hide Filament chrome (sidebar, topbar, padding) cuando esta página está activa --}}
    <style>
        body { overflow: hidden; }
        .fi-sidebar, .fi-topbar { display: none !important; }
        .fi-main-ctn { padding: 0 !important; max-width: 100vw !important; width: 100vw !important; margin: 0 !important; }
        .fi-page { padding: 0 !important; }
        .fi-page-header, .fi-page > nav, .fi-breadcrumbs, .fi-header-heading { display: none !important; }
        .fi-main { padding: 0 !important; }
        main.fi-main { width: 100vw !important; height: 100vh !important; max-width: 100vw !important; }
    </style>

    @php
        $totals = $this->totals();
        $cats = $this->categories;
        $products = $this->products;
    @endphp

    <div class="fixed inset-0 bg-gray-100 dark:bg-gray-950 flex flex-col text-gray-900 dark:text-gray-100"
         style="width:100vw; height:100vh;">

        {{-- ============================================================ --}}
        {{-- TOP BAR                                                       --}}
        {{-- ============================================================ --}}
        <header class="h-12 flex items-center justify-between px-4 bg-gray-800 text-white shrink-0">
            <div class="flex items-center gap-4 text-sm">
                <span class="font-semibold uppercase tracking-wide">Emprenddi POS</span>
                <span class="text-gray-300">·</span>
                <span class="text-gray-200">{{ $this->locationName }}</span>
                <span class="text-gray-400 text-xs">{{ now()->format('Y-m-d H:i') }}</span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="resetCart" wire:confirm="¿Vaciar carrito?"
                        class="px-3 py-1 text-xs rounded bg-gray-700 hover:bg-gray-600">Limpiar</button>
                <a href="{{ url('/app') }}" class="px-3 py-1 text-xs rounded bg-red-600 hover:bg-red-700">Salir</a>
            </div>
        </header>

        {{-- ============================================================ --}}
        {{-- BODY: 3 columnas                                              --}}
        {{-- ============================================================ --}}
        <div class="flex-1 flex min-h-0">

            {{-- =========== CATEGORÍAS =========== --}}
            <aside class="w-44 shrink-0 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 overflow-y-auto">
                <div class="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
                    Categorías
                </div>
                @foreach ($cats as $cat)
                    <button type="button"
                            wire:click="selectCategory({{ $cat->id ?? 'null' }})"
                            class="w-full text-left px-3 py-3 border-b border-gray-100 dark:border-gray-800 text-sm hover:bg-primary-50 dark:hover:bg-gray-800 transition flex items-center justify-between
                                   {{ $selectedCategoryId === $cat->id ? 'bg-primary-100 dark:bg-primary-950 border-l-4 border-l-primary-500 font-semibold' : '' }}">
                        <span class="truncate">{{ $cat->name }}</span>
                        @if ($cat->products_count !== null)
                            <span class="text-[10px] text-gray-400">{{ $cat->products_count }}</span>
                        @endif
                    </button>
                @endforeach
            </aside>

            {{-- =========== GRID DE PRODUCTOS =========== --}}
            <main class="flex-1 min-w-0 flex flex-col bg-gray-50 dark:bg-gray-950 overflow-hidden">
                {{-- Búsqueda --}}
                <div class="p-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                        <input type="text"
                               wire:model.live.debounce.250ms="productSearch"
                               placeholder="Buscar por código, nombre o escanear código de barras…"
                               class="w-full pl-10 pr-4 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 focus:ring-2 focus:ring-primary-500 outline-none" />
                    </div>
                </div>

                {{-- Grid --}}
                <div class="flex-1 overflow-y-auto p-3">
                    @if ($products->isEmpty())
                        <div class="h-full flex items-center justify-center text-sm text-gray-500">
                            @if ($productSearch)
                                Sin resultados para "{{ $productSearch }}"
                            @elseif ($selectedCategoryId)
                                No hay productos en esta categoría
                            @else
                                No hay productos cargados
                            @endif
                        </div>
                    @else
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                            @foreach ($products as $p)
                                <button type="button"
                                        wire:click="addProductToCart({{ $p->id }})"
                                        class="group bg-white dark:bg-gray-900 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-800 hover:border-primary-500 hover:shadow-lg transition flex flex-col text-left">
                                    <div class="aspect-square bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-800 dark:to-gray-900 flex items-center justify-center text-gray-400 dark:text-gray-600 overflow-hidden">
                                        @if ($p->image_path)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($p->image_path) }}"
                                                 alt="{{ $p->name }}" class="object-cover w-full h-full" />
                                        @else
                                            <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        @endif
                                    </div>
                                    <div class="p-2 flex-1 flex flex-col">
                                        <div class="text-[10px] font-mono text-gray-500 truncate">{{ $p->code }}</div>
                                        <div class="text-xs font-medium leading-tight line-clamp-2 group-hover:text-primary-600 dark:group-hover:text-primary-400">{{ $p->name }}</div>
                                        <div class="mt-auto pt-1 text-sm font-bold text-gray-900 dark:text-gray-100">
                                            ${{ number_format($p->default_sale_price, 0, ',', '.') }}
                                        </div>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </main>

            {{-- =========== CARRITO =========== --}}
            <aside class="w-96 shrink-0 bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col">
                {{-- Cliente --}}
                <div class="p-3 border-b border-gray-200 dark:border-gray-800 flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <button type="button" wire:click="$set('showCustomerModal', true)"
                            class="flex-1 text-left text-sm font-medium hover:text-primary-600 truncate">
                        {{ $this->customerName }}
                    </button>
                    <button type="button" wire:click="$set('showCustomerModal', true)"
                            class="w-7 h-7 rounded bg-primary-500 hover:bg-primary-600 text-white flex items-center justify-center text-lg leading-none">+</button>
                </div>

                {{-- Líneas del carrito --}}
                <div class="flex-1 overflow-y-auto">
                    @if (empty($cart))
                        <div class="h-full flex items-center justify-center text-sm text-gray-400 px-6 text-center">
                            Selecciona productos del catálogo para agregarlos
                        </div>
                    @else
                        <div class="divide-y divide-gray-200 dark:divide-gray-800">
                            <div class="grid grid-cols-12 gap-1 px-3 py-2 bg-gray-50 dark:bg-gray-950 text-[10px] uppercase font-semibold text-gray-500">
                                <div class="col-span-5">Producto</div>
                                <div class="col-span-3 text-center">Cant.</div>
                                <div class="col-span-3 text-right">Subtotal</div>
                                <div class="col-span-1"></div>
                            </div>
                            @foreach ($cart as $i => $line)
                                <div class="px-3 py-2 grid grid-cols-12 gap-1 items-center text-sm">
                                    <div class="col-span-5 min-w-0">
                                        <div class="font-medium truncate text-xs">{{ $line['description'] }}</div>
                                        <div class="text-[10px] text-gray-500">${{ number_format($line['unit_price'], 0, ',', '.') }} c/u</div>
                                    </div>
                                    <div class="col-span-3 flex items-center justify-center gap-1">
                                        <button type="button" wire:click="decLine({{ $i }})"
                                                class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-xs font-bold">−</button>
                                        <input type="number" step="0.01" min="0"
                                               wire:model.live.blur="cart.{{ $i }}.quantity"
                                               class="w-10 text-center text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-1 py-0.5" />
                                        <button type="button" wire:click="incLine({{ $i }})"
                                                class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-xs font-bold">+</button>
                                    </div>
                                    <div class="col-span-3 text-right font-semibold text-xs">
                                        ${{ number_format($line['total'], 0, ',', '.') }}
                                    </div>
                                    <div class="col-span-1 text-right">
                                        <button type="button" wire:click="removeLine({{ $i }})"
                                                class="text-red-500 hover:text-red-700 text-base">×</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Totales --}}
                <div class="border-t border-gray-200 dark:border-gray-800 p-3 space-y-1 text-xs">
                    <div class="flex justify-between text-gray-600 dark:text-gray-400">
                        <span>Artículos</span><span>{{ number_format($totals['items'], 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Subtotal</span><span>${{ number_format($totals['subtotal'], 0, ',', '.') }}</span>
                    </div>
                    @if ($totals['discount'] > 0)
                        <div class="flex justify-between text-amber-600">
                            <span>Descuento</span><span>−${{ number_format($totals['discount'], 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <span>IVA</span><span>${{ number_format($totals['tax'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-lg font-bold pt-1 border-t border-gray-200 dark:border-gray-800">
                        <span>TOTAL</span>
                        <span class="text-primary-600 dark:text-primary-400">${{ number_format($totals['total'], 0, ',', '.') }}</span>
                    </div>
                </div>
            </aside>
        </div>

        {{-- ============================================================ --}}
        {{-- BARRA INFERIOR DE ACCIONES                                   --}}
        {{-- ============================================================ --}}
        <footer class="h-16 bg-gray-800 text-white shrink-0 flex items-stretch">
            {{-- Acciones secundarias --}}
            <div class="flex items-center gap-2 px-3 border-r border-gray-700">
                <button type="button" wire:click="saveDraft"
                        @disabled(empty($cart))
                        class="px-3 py-2 rounded text-xs font-semibold bg-gray-600 hover:bg-gray-500 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Borrador
                </button>
            </div>

            {{-- Total a pagar (centro-izquierda) --}}
            <div class="flex items-center px-4 border-r border-gray-700">
                <div>
                    <div class="text-[10px] text-gray-400 uppercase tracking-wide">Total a pagar</div>
                    <div class="text-2xl font-bold leading-tight">${{ number_format($totals['total'], 0, ',', '.') }}</div>
                </div>
            </div>

            {{-- Botones de cobro --}}
            <div class="flex-1 flex items-stretch divide-x divide-gray-700">
                <button type="button" wire:click="openPayment('cash')"
                        @disabled(empty($cart))
                        class="flex-1 flex items-center justify-center gap-2 hover:bg-green-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-sm font-semibold">Efectivo</span>
                </button>
                <button type="button" wire:click="openPayment('card')"
                        @disabled(empty($cart))
                        class="flex-1 flex items-center justify-center gap-2 hover:bg-blue-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-sm font-semibold">Tarjeta</span>
                </button>
                <button type="button" wire:click="openPayment('transfer')"
                        @disabled(empty($cart))
                        class="flex-1 flex items-center justify-center gap-2 hover:bg-indigo-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span class="text-sm font-semibold">Transfer</span>
                </button>
                <button type="button" wire:click="openPayment('multi')"
                        @disabled(empty($cart))
                        class="flex-1 flex items-center justify-center gap-2 hover:bg-purple-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                    <span class="text-sm font-semibold">Multi-pago</span>
                </button>
                <button type="button" wire:click="openPayment('credit')"
                        @disabled(empty($cart))
                        class="flex-1 flex items-center justify-center gap-2 hover:bg-amber-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-sm font-semibold">A crédito</span>
                </button>
            </div>
        </footer>
    </div>

    {{-- ================================================================ --}}
    {{-- MODAL DE COBRO                                                    --}}
    {{-- ================================================================ --}}
    @if ($showPaymentModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
             wire:click.self="closePaymentModal">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl max-w-lg w-full max-h-[90vh] flex flex-col overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
                    <h2 class="text-base font-semibold">
                        @switch($paymentMode)
                            @case('cash') Cobro en efectivo @break
                            @case('card') Cobro con tarjeta @break
                            @case('transfer') Cobro por transferencia @break
                            @case('multi') Pago múltiple @break
                            @case('credit') Venta a crédito @break
                        @endswitch
                    </h2>
                    <button type="button" wire:click="closePaymentModal"
                            class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
                </div>

                <div class="p-5 space-y-4 overflow-y-auto">
                    {{-- Total a cobrar --}}
                    <div class="bg-primary-50 dark:bg-primary-950 rounded-lg p-4 text-center">
                        <div class="text-xs text-primary-700 dark:text-primary-300 uppercase font-semibold">Total a cobrar</div>
                        <div class="text-3xl font-bold text-primary-900 dark:text-primary-100">${{ number_format($totals['total'], 0, ',', '.') }}</div>
                    </div>

                    {{-- Crédito: no se cobra ahora --}}
                    @if ($paymentMode === 'credit')
                        <div class="bg-amber-50 dark:bg-amber-950 border border-amber-300 dark:border-amber-800 rounded-lg p-3 text-sm text-amber-900 dark:text-amber-100">
                            La factura quedará pendiente de pago. El cliente puede pagar después desde la vista detallada de la factura.
                        </div>
                    @else
                        {{-- Líneas de pago --}}
                        <div class="space-y-2">
                            @foreach ($payments as $i => $p)
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <select wire:model.live="payments.{{ $i }}.payment_method"
                                            class="col-span-5 text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-2">
                                        @foreach ($this->paymentMethods as $code => $label)
                                            <option value="{{ $code }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" step="0.01" min="0"
                                           wire:model.live.blur="payments.{{ $i }}.amount"
                                           class="col-span-5 text-right text-sm rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-2" />
                                    <button type="button" wire:click="removePayment({{ $i }})"
                                            class="col-span-2 text-red-500 hover:text-red-700 text-xl">×</button>
                                    @if (in_array($p['payment_method'] ?? '', ['bank_transfer', 'check', 'electronic'], true))
                                        <input type="text" placeholder="Referencia / Voucher"
                                               wire:model.blur="payments.{{ $i }}.reference"
                                               class="col-span-12 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1.5" />
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($paymentMode === 'multi')
                            <button type="button" wire:click="addPaymentLine"
                                    class="w-full px-3 py-2 text-sm rounded border border-dashed border-gray-300 dark:border-gray-700 hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-gray-800">
                                + Añadir método de pago
                            </button>
                        @endif

                        {{-- Resumen --}}
                        <div class="grid grid-cols-3 gap-2 text-center text-sm pt-2 border-t border-gray-200 dark:border-gray-800">
                            <div>
                                <div class="text-[10px] text-gray-500 uppercase">Pagado</div>
                                <div class="font-semibold">${{ number_format($totals['paid'], 0, ',', '.') }}</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-gray-500 uppercase">Falta</div>
                                <div class="font-semibold {{ $totals['remaining'] > 0 ? 'text-amber-600' : 'text-green-600' }}">
                                    ${{ number_format($totals['remaining'], 0, ',', '.') }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[10px] text-gray-500 uppercase">Vuelto</div>
                                <div class="font-semibold {{ $totals['change'] > 0 ? 'text-green-600' : '' }}">
                                    ${{ number_format($totals['change'], 0, ',', '.') }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-800 flex justify-end gap-2 bg-gray-50 dark:bg-gray-950">
                    <button type="button" wire:click="closePaymentModal"
                            class="px-4 py-2 text-sm rounded bg-gray-200 dark:bg-gray-800 hover:bg-gray-300 dark:hover:bg-gray-700">
                        Cancelar
                    </button>
                    <button type="button" wire:click="processSale"
                            wire:loading.attr="disabled"
                            class="px-6 py-2 text-sm font-bold rounded bg-green-600 hover:bg-green-700 text-white">
                        <span wire:loading.remove wire:target="processSale">Confirmar venta</span>
                        <span wire:loading wire:target="processSale">Procesando…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL CLIENTE --}}
    @if ($showCustomerModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
             wire:click.self="$set('showCustomerModal', false)">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4">
                <h2 class="text-lg font-semibold">Cliente de la venta</h2>
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
                            class="px-4 py-2 text-sm rounded bg-gray-200 dark:bg-gray-800 hover:bg-gray-300 dark:hover:bg-gray-700">Cancelar</button>
                    <button type="button" wire:click="createQuickCustomer"
                            class="px-4 py-2 text-sm rounded bg-primary-600 hover:bg-primary-700 text-white">Crear y usar</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
