<x-filament-panels::page>
    <style>
        body { overflow: hidden; }
        .fi-sidebar, .fi-topbar { display: none !important; }
        .fi-main-ctn { padding: 0 !important; max-width: 100vw !important; width: 100vw !important; margin: 0 !important; }
        .fi-page { padding: 0 !important; }
        .fi-page-header, .fi-page > nav, .fi-breadcrumbs, .fi-header-heading { display: none !important; }
        .fi-main { padding: 0 !important; }
        main.fi-main { width: 100vw !important; height: 100vh !important; max-width: 100vw !important; }

        /* Animaciones */
        @keyframes pos-fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes pos-slide-up { from { transform: translateY(24px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes pos-pulse-once { 0% { box-shadow: 0 0 0 0 rgba(99,102,241,0.6); } 100% { box-shadow: 0 0 0 14px rgba(99,102,241,0); } }
        @keyframes pos-shake { 0%,100% { transform: translateX(0); } 20%,60% { transform: translateX(-3px); } 40%,80% { transform: translateX(3px); } }

        .pos-modal-overlay { animation: pos-fade-in 180ms ease; }
        .pos-modal-content { animation: pos-slide-up 240ms cubic-bezier(0.16, 1, 0.3, 1); }
        .pos-cart-item { animation: pos-slide-up 220ms cubic-bezier(0.16, 1, 0.3, 1); }
        .pos-product-card:active { transform: scale(0.96); }
        .pos-product-card { transition: transform 120ms ease, box-shadow 200ms ease, border-color 150ms ease; }

        /* Scrollbar discreto */
        .pos-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .pos-scroll::-webkit-scrollbar-track { background: transparent; }
        .pos-scroll::-webkit-scrollbar-thumb { background: rgba(120,120,140,0.25); border-radius: 4px; }
        .pos-scroll::-webkit-scrollbar-thumb:hover { background: rgba(120,120,140,0.45); }
    </style>

    @php
        $totals = $this->totals();
        $cats = $this->categories;
        $products = $this->products;

        // Paleta de gradientes para el badge de la categoría — distribuida por id
        $catGradients = [
            ['from-blue-400','to-blue-600'],
            ['from-emerald-400','to-emerald-600'],
            ['from-violet-400','to-violet-600'],
            ['from-orange-400','to-orange-600'],
            ['from-pink-400','to-pink-600'],
            ['from-cyan-400','to-cyan-600'],
            ['from-amber-400','to-amber-600'],
            ['from-rose-400','to-rose-600'],
        ];
    @endphp

    <div class="fixed inset-0 flex flex-col bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100"
         style="width:100vw; height:100vh;">

        {{-- ============================================================ --}}
        {{-- TOP BAR — theme-aware                                        --}}
        {{-- ============================================================ --}}
        <header class="h-14 flex items-center justify-between px-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shrink-0 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center text-white text-sm font-bold shadow-sm">
                    E
                </div>
                <div>
                    <div class="text-sm font-semibold leading-tight">Emprenddi POS</div>
                    <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ $this->locationName }}</div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Cajero</div>
                    <div class="text-xs font-medium">{{ auth()->user()->name ?: auth()->user()->email }}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ now()->format('Y-m-d') }}</div>
                    <div class="text-xs font-medium font-mono">{{ now()->format('H:i') }}</div>
                </div>
                <button type="button" wire:click="resetCart" wire:confirm="¿Vaciar carrito?"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    Limpiar
                </button>
                <a href="{{ url('/app') }}"
                   class="px-3 py-1.5 text-xs font-medium rounded-lg bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 transition">
                    Salir
                </a>
            </div>
        </header>

        {{-- ============================================================ --}}
        {{-- BODY: 3 columnas                                              --}}
        {{-- ============================================================ --}}
        <div class="flex-1 flex min-h-0">

            {{-- =========== CATEGORÍAS =========== --}}
            <aside class="w-56 shrink-0 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 overflow-y-auto pos-scroll">
                <div class="px-4 py-3 sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 z-10">
                    <div class="text-[10px] font-bold uppercase tracking-widest text-gray-500 dark:text-gray-400">Categorías</div>
                </div>
                <div class="p-2 space-y-1">
                    @foreach ($cats as $idx => $cat)
                        @php
                            $g = $catGradients[$idx % count($catGradients)];
                            $isActive = $selectedCategoryId === $cat->id;
                        @endphp
                        <button type="button"
                                wire:click="selectCategory({{ $cat->id ?? 'null' }})"
                                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition group
                                       {{ $isActive
                                          ? 'bg-primary-500/15 text-primary-700 dark:text-primary-300 ring-1 ring-primary-500/40 shadow-sm'
                                          : 'hover:bg-gray-100 dark:hover:bg-gray-800/70' }}">
                            @if ($cat->id === null)
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-gray-400 to-gray-600 flex items-center justify-center text-white text-xs font-bold shrink-0 shadow-sm">★</div>
                            @else
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-br {{ $g[0] }} {{ $g[1] }} flex items-center justify-center text-white text-xs font-bold shrink-0 shadow-sm">
                                    {{ strtoupper(mb_substr($cat->name, 0, 1)) }}
                                </div>
                            @endif
                            <span class="flex-1 text-left truncate font-medium">{{ $cat->name }}</span>
                            @if ($cat->products_count !== null)
                                <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-gray-200 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono shrink-0">
                                    {{ $cat->products_count }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </aside>

            {{-- =========== GRID DE PRODUCTOS =========== --}}
            <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
                {{-- Búsqueda --}}
                <div class="px-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="relative max-w-2xl">
                        <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                        <input type="text"
                               wire:model.live.debounce.250ms="productSearch"
                               placeholder="Buscar por código, nombre o escanear código de barras…"
                               class="w-full pl-9 pr-4 py-2.5 text-sm rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/60 focus:bg-white dark:focus:bg-gray-900 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" />
                    </div>
                </div>

                {{-- Grid --}}
                <div class="flex-1 overflow-y-auto pos-scroll p-4 bg-gray-50 dark:bg-gray-950">
                    @if ($products->isEmpty())
                        <div class="h-full flex flex-col items-center justify-center text-center text-sm text-gray-400">
                            <svg class="w-16 h-16 mb-3 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            <div class="font-medium">
                                @if ($productSearch) Sin resultados para "{{ $productSearch }}"
                                @elseif ($selectedCategoryId) Sin productos en esta categoría
                                @else No hay productos cargados
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 2xl:grid-cols-8 gap-3">
                            @foreach ($products as $p)
                                <button type="button"
                                        wire:click="addProductToCart({{ $p->id }})"
                                        class="pos-product-card group bg-white dark:bg-gray-900 rounded-xl overflow-hidden border border-gray-200 dark:border-gray-800 hover:border-primary-500 hover:shadow-lg dark:hover:shadow-primary-500/10 flex flex-col text-left">
                                    <div class="aspect-square bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-900 flex items-center justify-center text-gray-300 dark:text-gray-700 overflow-hidden relative">
                                        @if ($p->image_path)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($p->image_path) }}"
                                                 alt="{{ $p->name }}"
                                                 class="object-cover w-full h-full group-hover:scale-105 transition-transform duration-300" />
                                        @else
                                            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        @endif
                                        <div class="absolute inset-0 bg-primary-500/0 group-hover:bg-primary-500/5 transition"></div>
                                    </div>
                                    <div class="px-2 py-2 flex-1 flex flex-col gap-0.5">
                                        <div class="text-[10px] font-mono text-gray-400 dark:text-gray-500 truncate">{{ $p->code }}</div>
                                        <div class="text-xs font-medium leading-tight line-clamp-2 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition">{{ $p->name }}</div>
                                        <div class="mt-auto pt-1 text-sm font-bold text-gray-900 dark:text-white">
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
            <aside class="w-[26rem] shrink-0 bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col">
                {{-- Cliente --}}
                <div class="p-3 border-b border-gray-200 dark:border-gray-800 flex items-center gap-2">
                    <div class="w-9 h-9 rounded-lg bg-primary-500/15 text-primary-600 dark:text-primary-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <button type="button" wire:click="$set('showCustomerModal', true)"
                            class="flex-1 text-left min-w-0 group">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold">Cliente</div>
                        <div class="text-sm font-medium truncate group-hover:text-primary-600 dark:group-hover:text-primary-400 transition">
                            {{ $this->customerName }}
                        </div>
                    </button>
                    <button type="button" wire:click="$set('showCustomerModal', true)"
                            class="w-8 h-8 rounded-lg bg-primary-500 hover:bg-primary-600 text-white flex items-center justify-center shadow-sm transition"
                            title="Cambiar cliente">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    </button>
                </div>

                {{-- Líneas del carrito --}}
                <div class="flex-1 overflow-y-auto pos-scroll" wire:key="cart-list">
                    @if (empty($cart))
                        <div class="h-full flex flex-col items-center justify-center text-center text-sm text-gray-400 px-6 gap-3">
                            <svg class="w-12 h-12 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <div>Selecciona productos del catálogo</div>
                        </div>
                    @else
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($cart as $i => $line)
                                <div class="pos-cart-item p-3 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition" wire:key="cart-{{ $i }}-{{ $line['product_id'] }}">
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium leading-tight">{{ $line['description'] }}</div>
                                            <div class="text-[11px] text-gray-500 dark:text-gray-400 font-mono mt-0.5">{{ $line['code'] ?? '—' }}</div>
                                        </div>
                                        <button type="button" wire:click="removeLine({{ $i }})"
                                                class="text-gray-400 hover:text-red-500 transition"
                                                title="Eliminar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-12 gap-2 items-center">
                                        <div class="col-span-5 flex items-center gap-1">
                                            <button type="button" wire:click="decLine({{ $i }})"
                                                    class="w-7 h-7 rounded-md bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-sm font-bold transition active:scale-90">−</button>
                                            <input type="number" step="0.01" min="0"
                                                   wire:model.live.blur="cart.{{ $i }}.quantity"
                                                   class="w-12 text-center text-sm font-semibold rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-1 py-1 outline-none focus:ring-2 focus:ring-primary-500" />
                                            <button type="button" wire:click="incLine({{ $i }})"
                                                    class="w-7 h-7 rounded-md bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-sm font-bold transition active:scale-90">+</button>
                                        </div>
                                        <div class="col-span-4">
                                            <div class="text-[10px] text-gray-400 mb-0.5">Precio</div>
                                            <input type="number" step="0.01" min="0"
                                                   wire:model.live.blur="cart.{{ $i }}.unit_price"
                                                   class="w-full text-right text-xs rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 outline-none focus:ring-2 focus:ring-primary-500" />
                                        </div>
                                        <div class="col-span-3 text-right">
                                            <div class="text-[10px] text-gray-400 mb-0.5">Subtotal</div>
                                            <div class="text-sm font-bold text-primary-600 dark:text-primary-400 whitespace-nowrap">
                                                ${{ number_format($line['total'], 0, ',', '.') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Totales --}}
                <div class="border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950">
                    <div class="px-4 pt-3 pb-2 space-y-1.5 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>Artículos</span>
                            <span class="font-medium">{{ number_format($totals['items'], 0) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>Subtotal</span>
                            <span class="font-medium">${{ number_format($totals['subtotal'], 0, ',', '.') }}</span>
                        </div>
                        @if ($totals['discount'] > 0)
                            <div class="flex justify-between text-amber-600">
                                <span>Descuento</span>
                                <span class="font-medium">−${{ number_format($totals['discount'], 0, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>IVA</span>
                            <span class="font-medium">${{ number_format($totals['tax'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    <div class="px-4 py-3 bg-primary-500 text-white flex items-baseline justify-between">
                        <span class="text-xs uppercase tracking-widest font-semibold opacity-90">Total</span>
                        <span class="text-3xl font-bold tracking-tight">${{ number_format($totals['total'], 0, ',', '.') }}</span>
                    </div>
                </div>
            </aside>
        </div>

        {{-- ============================================================ --}}
        {{-- BARRA INFERIOR — Acciones                                    --}}
        {{-- ============================================================ --}}
        <footer class="h-16 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 shrink-0 flex items-stretch shadow-[0_-1px_3px_rgba(0,0,0,0.04)]">
            {{-- Borrador --}}
            <button type="button" wire:click="saveDraft"
                    @disabled(empty($cart))
                    class="px-4 flex items-center gap-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-30 disabled:cursor-not-allowed transition border-r border-gray-200 dark:border-gray-800">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span class="hidden sm:inline">Borrador</span>
            </button>

            {{-- Botones de cobro --}}
            <div class="flex-1 flex items-stretch">
                <button type="button" wire:click="openPayment('cash')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition relative">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-xs font-semibold">Efectivo</span>
                </button>
                <button type="button" wire:click="openPayment('card')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-blue-50 dark:hover:bg-blue-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-xs font-semibold">Tarjeta</span>
                </button>
                <button type="button" wire:click="openPayment('transfer')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span class="text-xs font-semibold">Transferencia</span>
                </button>
                <button type="button" wire:click="openPayment('multi')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-purple-50 dark:hover:bg-purple-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    <span class="text-xs font-semibold">Multi-pago</span>
                </button>
                <button type="button" wire:click="openPayment('credit')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-amber-50 dark:hover:bg-amber-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-xs font-semibold">A crédito</span>
                </button>
            </div>
        </footer>
    </div>

    {{-- ================================================================ --}}
    {{-- MODAL DE COBRO                                                    --}}
    {{-- ================================================================ --}}
    @if ($showPaymentModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="closePaymentModal">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-lg w-full max-h-[90vh] flex flex-col overflow-hidden">
                {{-- Header --}}
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @php
                            // Map estático: Tailwind JIT no resuelve clases dinámicas con interpolación.
                            $modeBadge = match($paymentMode) {
                                'cash' => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
                                'card' => 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
                                'transfer' => 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400',
                                'multi' => 'bg-purple-500/15 text-purple-600 dark:text-purple-400',
                                'credit' => 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
                                default => 'bg-gray-500/15 text-gray-600 dark:text-gray-400',
                            };
                        @endphp
                        <div class="w-10 h-10 rounded-xl {{ $modeBadge }} flex items-center justify-center">
                            @switch($paymentMode)
                                @case('cash') <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> @break
                                @case('card') <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg> @break
                                @case('transfer') <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg> @break
                                @case('multi') <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg> @break
                                @case('credit') <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> @break
                            @endswitch
                        </div>
                        <h2 class="text-base font-semibold">
                            @switch($paymentMode)
                                @case('cash') Cobro en efectivo @break
                                @case('card') Cobro con tarjeta @break
                                @case('transfer') Cobro por transferencia @break
                                @case('multi') Pago múltiple @break
                                @case('credit') Venta a crédito @break
                            @endswitch
                        </h2>
                    </div>
                    <button type="button" wire:click="closePaymentModal"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>

                <div class="p-5 space-y-4 overflow-y-auto pos-scroll">
                    {{-- Total grande --}}
                    <div class="bg-gradient-to-br from-primary-500 to-primary-700 rounded-2xl p-5 text-center text-white shadow-lg">
                        <div class="text-xs uppercase tracking-widest opacity-90 font-semibold">Total a cobrar</div>
                        <div class="text-4xl font-bold tracking-tight mt-1">${{ number_format($totals['total'], 0, ',', '.') }}</div>
                    </div>

                    @if ($paymentMode === 'credit')
                        <div class="bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-xl p-4 text-sm text-amber-900 dark:text-amber-200 flex gap-3">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <div>La factura quedará pendiente de pago. El cliente puede pagar después desde la vista detallada.</div>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach ($payments as $i => $p)
                                <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-3 space-y-2 ring-1 ring-gray-100 dark:ring-gray-800">
                                    <div class="grid grid-cols-12 gap-2 items-center">
                                        <select wire:model.live="payments.{{ $i }}.payment_method"
                                                class="col-span-5 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 outline-none focus:ring-2 focus:ring-primary-500">
                                            @foreach ($this->paymentMethods as $code => $label)
                                                <option value="{{ $code }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input type="number" step="0.01" min="0"
                                               wire:model.live.blur="payments.{{ $i }}.amount"
                                               placeholder="0"
                                               class="col-span-6 text-right text-base font-semibold rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 outline-none focus:ring-2 focus:ring-primary-500" />
                                        <button type="button" wire:click="removePayment({{ $i }})"
                                                class="col-span-1 w-8 h-8 mx-auto rounded-lg text-gray-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950 transition flex items-center justify-center">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    @if (in_array($p['payment_method'] ?? '', ['bank_transfer', 'check', 'electronic'], true))
                                        <input type="text" placeholder="Referencia / Voucher / Comprobante"
                                               wire:model.blur="payments.{{ $i }}.reference"
                                               class="w-full text-xs rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-1.5 outline-none focus:ring-2 focus:ring-primary-500" />
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($paymentMode === 'multi')
                            <button type="button" wire:click="addPaymentLine"
                                    class="w-full px-3 py-2.5 text-sm font-medium rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-950/30 hover:text-primary-600 transition flex items-center justify-center gap-1">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                Añadir método de pago
                            </button>
                        @endif

                        {{-- Resumen --}}
                        <div class="grid grid-cols-3 gap-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/40 p-2.5 text-center">
                                <div class="text-[10px] text-gray-500 uppercase font-semibold">Pagado</div>
                                <div class="text-base font-bold mt-0.5">${{ number_format($totals['paid'], 0, ',', '.') }}</div>
                            </div>
                            <div class="rounded-lg p-2.5 text-center {{ $totals['remaining'] > 0 ? 'bg-amber-50 dark:bg-amber-950/40' : 'bg-emerald-50 dark:bg-emerald-950/40' }}">
                                <div class="text-[10px] uppercase font-semibold {{ $totals['remaining'] > 0 ? 'text-amber-600' : 'text-emerald-600' }}">
                                    {{ $totals['remaining'] > 0 ? 'Falta' : 'Cubierto' }}
                                </div>
                                <div class="text-base font-bold mt-0.5 {{ $totals['remaining'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300' }}">
                                    ${{ number_format($totals['remaining'], 0, ',', '.') }}
                                </div>
                            </div>
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/40 p-2.5 text-center">
                                <div class="text-[10px] text-gray-500 uppercase font-semibold">Vuelto</div>
                                <div class="text-base font-bold mt-0.5 {{ $totals['change'] > 0 ? 'text-emerald-600' : '' }}">
                                    ${{ number_format($totals['change'], 0, ',', '.') }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2 bg-gray-50 dark:bg-gray-950/50">
                    <button type="button" wire:click="closePaymentModal"
                            class="px-5 py-2.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                        Cancelar
                    </button>
                    <button type="button" wire:click="processSale"
                            wire:loading.attr="disabled"
                            class="px-6 py-2.5 text-sm font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-md hover:shadow-lg transition disabled:opacity-70 disabled:cursor-wait flex items-center gap-2">
                        <svg wire:loading.remove wire:target="processSale" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        <svg wire:loading wire:target="processSale" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                        <span wire:loading.remove wire:target="processSale">Confirmar venta</span>
                        <span wire:loading wire:target="processSale">Procesando…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL CLIENTE                                                     --}}
    {{-- ================================================================ --}}
    @if ($showCustomerModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="$set('showCustomerModal', false)">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-md w-full overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Cliente de la venta</h2>
                    <button type="button" wire:click="$set('showCustomerModal', false)"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>
                <div class="p-5 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-gray-700 dark:text-gray-300">Nombre / razón social</label>
                        <input type="text" wire:model="newCustomerName" placeholder="Juan Pérez Gómez"
                               class="w-full mt-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 dark:text-gray-300">Documento (CC / NIT)</label>
                        <input type="text" wire:model="newCustomerDocument" placeholder="1234567890"
                               class="w-full mt-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-primary-500" />
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2 bg-gray-50 dark:bg-gray-950/50">
                    <button type="button" wire:click="$set('showCustomerModal', false)"
                            class="px-5 py-2.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Cancelar</button>
                    <button type="button" wire:click="createQuickCustomer"
                            class="px-5 py-2.5 text-sm font-bold rounded-lg bg-primary-600 hover:bg-primary-700 text-white shadow-md transition">Crear y usar</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
