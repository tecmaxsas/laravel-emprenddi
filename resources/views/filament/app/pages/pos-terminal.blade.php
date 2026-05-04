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

        /* ====== GRID DE PRODUCTOS ======
           Independiente del bundle de Filament: usar CSS Grid puro con auto-fill +
           minmax garantiza tarjetas ~160-180px touch-friendly que se acomodan al
           ancho disponible. */
        .pos-products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        .pos-product-card {
            display: flex;
            flex-direction: column;
            text-align: left;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 120ms ease, box-shadow 200ms ease, border-color 150ms ease;
            font: inherit;
            color: inherit;
            padding: 0;
        }
        :is(.dark) .pos-product-card { background: #111827; border-color: #1f2937; }
        .pos-product-card:hover {
            border-color: rgb(99, 102, 241);
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.10), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        :is(.dark) .pos-product-card:hover {
            box-shadow: 0 8px 16px -4px rgba(99,102,241,0.15);
        }
        .pos-product-card:active { transform: scale(0.96); }

        .pos-product-image {
            aspect-ratio: 1 / 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f9fafb, #f3f4f6);
            color: #d1d5db;
            overflow: hidden;
            position: relative;
        }
        :is(.dark) .pos-product-image { background: linear-gradient(135deg, #1f2937, #111827); color: #374151; }
        .pos-product-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 300ms ease; }
        .pos-product-card:hover .pos-product-image img { transform: scale(1.05); }

        .pos-product-info { padding: 8px 10px 10px; display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .pos-product-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 10px; color: #9ca3af; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        :is(.dark) .pos-product-code { color: #6b7280; }
        .pos-product-name { font-size: 12px; font-weight: 500; line-height: 1.25; color: #111827; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 30px; }
        :is(.dark) .pos-product-name { color: #f3f4f6; }
        .pos-product-card:hover .pos-product-name { color: rgb(79, 70, 229); }
        :is(.dark) .pos-product-card:hover .pos-product-name { color: rgb(165, 180, 252); }
        .pos-product-price { margin-top: auto; padding-top: 4px; font-size: 14px; font-weight: 700; color: #111827; }
        :is(.dark) .pos-product-price { color: #ffffff; }
    </style>

    @php
        $totals = $this->totals();
        $cats = $this->categories;
        $products = $this->products;
        $posSettings = $this->posSettings;
        $hasSession = $this->hasOpenSession;
        $session = $this->currentSession;
        $sessionTotals = $this->sessionTotals;
    @endphp

    {{-- ============================================================ --}}
    {{-- APERTURA DE CAJA — bloquea POS si no hay sesión abierta       --}}
    {{-- ============================================================ --}}
    @if (! $hasSession)
        <div class="fixed inset-0 flex items-center justify-center bg-gray-50 dark:bg-gray-950 p-6"
             style="width:100vw; height:100vh;">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-md w-full overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white flex items-center justify-center shadow-lg">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold">Apertura de caja</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Abre tu turno antes de empezar a vender.</p>
                    </div>
                </div>

                <form wire:submit.prevent="openCashSession" class="p-6 space-y-4">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wider text-gray-500">Sede</label>
                        <select wire:model.live="openingLocationId" required
                                class="w-full mt-1 px-3 py-2.5 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-emerald-500">
                            <option value="">— Selecciona —</option>
                            @foreach (\App\Models\Location::query()->where('active', true)->orderByDesc('is_main')->orderBy('name')->get() as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->fullName() }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-500 mt-1">La sede determina el inventario y catálogo de productos disponibles en este turno.</p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wider text-gray-500">Valor de apertura</label>
                        <div class="relative mt-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">$</span>
                            <input type="number" step="0.01" min="0"
                                   wire:model="openingAmount" placeholder="0"
                                   class="w-full pl-7 pr-3 py-2.5 text-base font-semibold rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-emerald-500" />
                        </div>
                        <p class="text-[11px] text-gray-500 mt-1">Efectivo que ya está en la caja al iniciar el turno.</p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wider text-gray-500">Notas (opcional)</label>
                        <textarea wire:model="openingNotes" rows="2" placeholder="Cambio recibido, observaciones..."
                                  class="w-full mt-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <a href="{{ url('/app') }}"
                           class="px-5 py-2.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            Cancelar
                        </a>
                        <button type="submit"
                                class="px-6 py-2.5 text-sm font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-md transition">
                            Abrir caja
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @else
    {{-- ============================================================ --}}
    {{-- POS NORMAL — solo si hay sesión abierta                       --}}
    {{-- ============================================================ --}}
    @php
        $sessionId = $session?->id;
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
                <button type="button" wire:click="$set('showRecoverModal', true)"
                        class="relative px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-300 hover:bg-amber-500/20 transition flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10v6a2 2 0 002 2h14a2 2 0 002-2v-6M3 10l9-7 9 7M3 10h18"/></svg>
                    Recuperar
                    @if ($this->suspendedSales->isNotEmpty())
                        <span class="ml-1 px-1.5 py-0.5 rounded-md bg-amber-500 text-white text-[10px] font-bold leading-none">{{ $this->suspendedSales->count() }}</span>
                    @endif
                </button>
                <button type="button" wire:click="openRetentionsModal"
                        @disabled(empty($cart))
                        class="relative px-3 py-1.5 text-xs font-medium rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-300 hover:bg-rose-500/20 transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5"
                        title="Aplicar retenciones (B2B con cliente agente retenedor)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Retenciones
                    @if (! empty($retentions))
                        <span class="ml-1 px-1.5 py-0.5 rounded-md bg-rose-500 text-white text-[10px] font-bold leading-none">{{ count($retentions) }}</span>
                    @endif
                </button>
                <button type="button" wire:click="openSuspendModal"
                        @disabled(empty($cart))
                        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-purple-500/10 text-purple-700 dark:text-purple-300 hover:bg-purple-500/20 transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Suspender
                </button>
                <button type="button" wire:click="resetCart" wire:confirm="¿Vaciar carrito?"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    Limpiar
                </button>

                {{-- Detalles caja: oculto si blind_cash_close, salvo admin/manager --}}
                @if (! ($posSettings['blind_cash_close'] ?? false) || auth()->user()->hasAnyRole(['admin', 'manager']))
                    <button type="button" wire:click="openSessionDetailsModal"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg bg-cyan-500/10 text-cyan-700 dark:text-cyan-300 hover:bg-cyan-500/20 transition flex items-center gap-1.5"
                            title="Ver detalles de la caja en curso">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Detalles caja
                    </button>
                @endif

                @if (auth()->user()->can('pos.cash_close'))
                    <button type="button" wire:click="openCloseSessionModal"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/20 transition flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                        Cerrar caja
                    </button>
                @endif

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
                        {{-- Inline grid: independiente del bundle de Filament. Pensado para touch:
                             tarjetas mínimo 150px que se acomodan automáticamente al ancho. --}}
                        <div class="pos-products-grid">
                            @foreach ($products as $p)
                                <button type="button"
                                        wire:click="addProductToCart({{ $p->id }})"
                                        class="pos-product-card">
                                    <div class="pos-product-image">
                                        @if ($p->image_path)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($p->image_path) }}"
                                                 alt="{{ $p->name }}" />
                                        @else
                                            <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        @endif
                                    </div>
                                    <div class="pos-product-info">
                                        <div class="pos-product-code">{{ $p->code }}</div>
                                        <div class="pos-product-name">{{ $p->name }}</div>
                                        <div class="pos-product-price">${{ number_format($p->default_sale_price, 0, ',', '.') }}</div>
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
                                            @if ($posSettings['allow_price_modification'])
                                                <input type="number" step="0.01" min="0"
                                                       wire:model.live.blur="cart.{{ $i }}.unit_price"
                                                       class="w-full text-right text-xs rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 outline-none focus:ring-2 focus:ring-primary-500" />
                                            @else
                                                <div class="w-full text-right text-xs px-2 py-1 text-gray-700 dark:text-gray-300 font-medium">
                                                    ${{ number_format($line['unit_price'], 0, ',', '.') }}
                                                </div>
                                            @endif
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
                        @if ($totals['retentions'] > 0)
                            <div class="flex justify-between text-rose-600 dark:text-rose-400">
                                <span>Retenciones</span>
                                <span class="font-medium">−${{ number_format($totals['retentions'], 0, ',', '.') }}</span>
                            </div>
                            <div class="flex justify-between pt-1 border-t border-dashed border-gray-300 dark:border-gray-700">
                                <span class="text-gray-500">Total factura</span>
                                <span class="font-medium">${{ number_format($totals['total'], 0, ',', '.') }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="px-4 py-3 bg-primary-500 text-white flex items-baseline justify-between">
                        <span class="text-xs uppercase tracking-widest font-semibold opacity-90">
                            {{ $totals['retentions'] > 0 ? 'Neto a pagar' : 'Total' }}
                        </span>
                        <span class="text-3xl font-bold tracking-tight">${{ number_format($totals['net_payable'], 0, ',', '.') }}</span>
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
    @endif {{-- end @else hasSession --}}

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
                    {{-- Total grande — net_payable cuando hay retenciones --}}
                    <div class="bg-gradient-to-br from-primary-500 to-primary-700 rounded-2xl p-5 text-center text-white shadow-lg">
                        <div class="text-xs uppercase tracking-widest opacity-90 font-semibold">
                            {{ $totals['retentions'] > 0 ? 'Neto a cobrar (con retenciones aplicadas)' : 'Total a cobrar' }}
                        </div>
                        <div class="text-4xl font-bold tracking-tight mt-1">${{ number_format($totals['net_payable'], 0, ',', '.') }}</div>
                        @if ($totals['retentions'] > 0)
                            <div class="text-xs opacity-90 mt-1">
                                Total factura ${{ number_format($totals['total'], 0, ',', '.') }} − retenciones ${{ number_format($totals['retentions'], 0, ',', '.') }}
                            </div>
                        @endif
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
    {{-- MODAL — SUSPENDER VENTA                                           --}}
    {{-- ================================================================ --}}
    @if ($showSuspendModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="$set('showSuspendModal', false)">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-md w-full overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h2 class="text-base font-semibold flex-1">Suspender venta</h2>
                    <button type="button" wire:click="$set('showSuspendModal', false)"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>
                <div class="p-5 space-y-3">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        El carrito actual se guardará como venta suspendida y se vaciará. Podrás recuperarla cuando quieras.
                    </p>
                    <div>
                        <label class="text-xs font-medium text-gray-700 dark:text-gray-300">Etiqueta (opcional)</label>
                        <input type="text" wire:model="suspendName" placeholder="Mesa 5, María Pérez, etc."
                               class="w-full mt-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-primary-500" />
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-800/40 rounded-lg p-3 text-sm flex justify-between">
                        <span class="text-gray-500">Items: <strong>{{ $totals['items'] }}</strong></span>
                        <span class="text-gray-500">Total: <strong>${{ number_format($totals['total'], 0, ',', '.') }}</strong></span>
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2 bg-gray-50 dark:bg-gray-950/50">
                    <button type="button" wire:click="$set('showSuspendModal', false)"
                            class="px-5 py-2.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Cancelar</button>
                    <button type="button" wire:click="suspendSale"
                            class="px-5 py-2.5 text-sm font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white shadow-md transition">
                        Suspender
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL — RECUPERAR VENTA                                           --}}
    {{-- ================================================================ --}}
    @if ($showRecoverModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="$set('showRecoverModal', false)">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-2xl w-full max-h-[80vh] flex flex-col overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10v6a2 2 0 002 2h14a2 2 0 002-2v-6M3 10l9-7 9 7M3 10h18"/></svg>
                    </div>
                    <h2 class="text-base font-semibold flex-1">Ventas suspendidas</h2>
                    <button type="button" wire:click="$set('showRecoverModal', false)"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>
                <div class="flex-1 overflow-y-auto pos-scroll p-3">
                    @if ($this->suspendedSales->isEmpty())
                        <div class="py-12 text-center text-sm text-gray-400">
                            No hay ventas suspendidas en esta sede.
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach ($this->suspendedSales as $s)
                                <div class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-800 hover:border-primary-500 hover:bg-primary-50/40 dark:hover:bg-primary-950/20 transition">
                                    <div class="w-10 h-10 rounded-lg bg-purple-500/15 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold truncate">{{ $s->name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                            {{ $s->customer?->name ?? '—' }} ·
                                            {{ $s->seller?->name ?? $s->seller?->email ?? '?' }} ·
                                            {{ $s->created_at->format('Y-m-d H:i') }}
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-[10px] uppercase text-gray-400 font-semibold">{{ $s->items_count }} items</div>
                                        <div class="text-sm font-bold">${{ number_format((float) $s->total, 0, ',', '.') }}</div>
                                    </div>
                                    <div class="flex flex-col gap-1 shrink-0">
                                        <button type="button" wire:click="recoverSale({{ $s->id }})"
                                                class="px-3 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition">
                                            Recuperar
                                        </button>
                                        <button type="button" wire:click="deleteSuspendedSale({{ $s->id }})"
                                                wire:confirm="¿Eliminar esta venta suspendida?"
                                                class="px-3 py-1 text-[11px] rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40 transition">
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- LISTENER de impresión: dispara una ventana nueva con el ticket    --}}
    {{-- ================================================================ --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('pos-print-ticket', (event) => {
                const id = Array.isArray(event) ? event[0]?.invoiceId : event?.invoiceId;
                if (! id) return;
                const url = '{{ url('/app/pos/print') }}/' + id;
                window.open(url, 'pos-print-' + id, 'width=420,height=720');
            });
        });
    </script>

    {{-- ================================================================ --}}
    {{-- MODAL — RETENCIONES                                               --}}
    {{-- ================================================================ --}}
    @if ($showRetentionsModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="$set('showRetentionsModal', false)">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/15 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-base font-semibold">Retenciones aplicables</h2>
                        <p class="text-xs text-gray-500">Aplican cuando el cliente es agente retenedor (Gran Contribuyente, Estado, etc.). Para venta a consumidor final, deja la lista vacía.</p>
                    </div>
                    <button type="button" wire:click="$set('showRetentionsModal', false)"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>

                <div class="p-5 space-y-4 overflow-y-auto pos-scroll">
                    {{-- Catálogo: Tax con type=*_withholding --}}
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Disponibles</div>
                        @if ($this->availableRetentionTaxes->isEmpty())
                            <div class="text-sm text-gray-400 italic px-3 py-4 bg-gray-50 dark:bg-gray-800/40 rounded-lg">
                                No hay impuestos de retención configurados. Configúralos en Contabilidad → Impuestos con type ReteFuente / ReteIVA / ReteICA.
                            </div>
                        @else
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($this->availableRetentionTaxes as $rt)
                                    @php
                                        $alreadyApplied = collect($retentions)->contains(fn ($r) => (int) $r['tax_id'] === (int) $rt->id);
                                    @endphp
                                    <button type="button"
                                            wire:click="addRetention({{ $rt->id }})"
                                            @disabled($alreadyApplied)
                                            class="text-left px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 disabled:opacity-40 disabled:cursor-not-allowed transition">
                                        <div class="text-xs font-mono text-gray-500">{{ $rt->code }}</div>
                                        <div class="text-sm font-semibold">{{ $rt->name }}</div>
                                        <div class="text-xs text-gray-500">{{ number_format((float) $rt->rate, 4) }} %</div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Aplicadas --}}
                    @if (! empty($retentions))
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Aplicadas a esta venta</div>
                            <div class="space-y-2">
                                @foreach ($retentions as $i => $r)
                                    <div class="bg-rose-50/50 dark:bg-rose-950/20 ring-1 ring-rose-200 dark:ring-rose-900 rounded-xl p-3 grid grid-cols-12 gap-2 items-center">
                                        <div class="col-span-5 min-w-0">
                                            <div class="text-xs font-mono text-gray-500">{{ $r['tax_code'] ?? '' }}</div>
                                            <div class="text-sm font-semibold truncate">{{ $r['tax_name'] ?? '' }}</div>
                                            <div class="text-xs text-gray-500">{{ number_format((float) $r['rate'], 4) }} %</div>
                                        </div>
                                        <div class="col-span-3">
                                            <div class="text-[10px] text-gray-500 mb-0.5">Base</div>
                                            <input type="number" step="0.01" min="0"
                                                   wire:model.live.blur="retentions.{{ $i }}.base_amount"
                                                   class="w-full text-right text-xs rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 outline-none focus:ring-2 focus:ring-rose-500" />
                                        </div>
                                        <div class="col-span-3 text-right">
                                            <div class="text-[10px] text-gray-500 mb-0.5">Retenido</div>
                                            <div class="text-sm font-bold text-rose-600 dark:text-rose-400">
                                                ${{ number_format((float) ($r['amount'] ?? 0), 0, ',', '.') }}
                                            </div>
                                        </div>
                                        <div class="col-span-1 text-right">
                                            <button type="button" wire:click="removeRetention({{ $i }})"
                                                    class="text-gray-400 hover:text-red-500 text-lg" title="Quitar">×</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Resumen --}}
                    <div class="grid grid-cols-3 gap-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/40 p-2.5 text-center">
                            <div class="text-[10px] text-gray-500 uppercase font-semibold">Total factura</div>
                            <div class="text-base font-bold mt-0.5">${{ number_format($totals['total'], 0, ',', '.') }}</div>
                        </div>
                        <div class="rounded-lg p-2.5 text-center bg-rose-50 dark:bg-rose-950/40">
                            <div class="text-[10px] text-rose-600 uppercase font-semibold">Retenciones</div>
                            <div class="text-base font-bold mt-0.5 text-rose-700 dark:text-rose-300">−${{ number_format($totals['retentions'], 0, ',', '.') }}</div>
                        </div>
                        <div class="rounded-lg p-2.5 text-center bg-emerald-50 dark:bg-emerald-950/40">
                            <div class="text-[10px] text-emerald-600 uppercase font-semibold">Neto a pagar</div>
                            <div class="text-base font-bold mt-0.5 text-emerald-700 dark:text-emerald-300">${{ number_format($totals['net_payable'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2 bg-gray-50 dark:bg-gray-950/50">
                    <button type="button" wire:click="$set('showRetentionsModal', false)"
                            class="px-5 py-2.5 text-sm font-bold rounded-lg bg-rose-600 hover:bg-rose-700 text-white shadow-md transition">
                        Listo
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL — DETALLES DE CAJA                                          --}}
    {{-- ================================================================ --}}
    @if ($showSessionDetailsModal && $session && $sessionTotals)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="$set('showSessionDetailsModal', false)">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-md w-full overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-base font-semibold">Caja en curso</h2>
                        <p class="text-xs text-gray-500">Abierta {{ $session->opened_at->diffForHumans() }} · {{ $session->location?->name }}</p>
                    </div>
                    <button type="button" wire:click="$set('showSessionDetailsModal', false)"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>

                <div class="p-5 space-y-4">
                    {{-- Resumen --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-3">
                            <div class="text-[10px] uppercase font-bold text-gray-500">Apertura</div>
                            <div class="text-lg font-bold mt-0.5">${{ number_format((float) $session->opening_amount, 0, ',', '.') }}</div>
                        </div>
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-3">
                            <div class="text-[10px] uppercase font-bold text-gray-500">Facturas emitidas</div>
                            <div class="text-lg font-bold mt-0.5">{{ $sessionTotals['invoice_count'] }}</div>
                        </div>
                    </div>

                    {{-- Pagos por método --}}
                    <div>
                        <div class="text-[10px] uppercase font-bold text-gray-500 mb-2">Cobros por método</div>
                        @if (empty($sessionTotals['payment_breakdown']))
                            <div class="text-sm text-gray-400 italic px-3 py-2">Sin cobros aún.</div>
                        @else
                            <div class="space-y-1">
                                @foreach ($sessionTotals['payment_breakdown'] as $method => $amount)
                                    <div class="flex justify-between text-sm py-1 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/40">
                                        <span>{{ \App\Models\Payment::PAYMENT_METHODS[$method] ?? $method }}</span>
                                        <span class="font-semibold">${{ number_format($amount, 0, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Total ventas + esperado en caja --}}
                    <div class="space-y-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Total ventas</span>
                            <span class="font-semibold">${{ number_format($sessionTotals['total_sales'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Recibido en efectivo</span>
                            <span class="font-semibold">${{ number_format($sessionTotals['cash_received'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-base font-bold pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span>Esperado en caja</span>
                            <span class="text-emerald-600 dark:text-emerald-400">${{ number_format($sessionTotals['closing_expected'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>

                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end bg-gray-50 dark:bg-gray-950/50">
                    <button type="button" wire:click="$set('showSessionDetailsModal', false)"
                            class="px-5 py-2.5 text-sm font-bold rounded-lg bg-cyan-600 hover:bg-cyan-700 text-white shadow-md transition">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL — CIERRE DE CAJA (respeta blind_cash_close)                 --}}
    {{-- ================================================================ --}}
    @if ($showCloseSessionModal && $session && $sessionTotals)
        @php $blindClose = (bool) ($posSettings['blind_cash_close'] ?? false); @endphp
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 pos-modal-overlay"
             wire:click.self="$set('showCloseSessionModal', false)">
            <div class="pos-modal-content bg-white dark:bg-gray-900 rounded-2xl shadow-2xl ring-1 ring-black/5 dark:ring-white/10 max-w-md w-full overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-base font-semibold">Cierre de caja</h2>
                        <p class="text-xs text-gray-500">{{ $session->location?->name }} · turno desde {{ $session->opened_at->format('Y-m-d H:i') }}</p>
                    </div>
                    <button type="button" wire:click="$set('showCloseSessionModal', false)"
                            class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center text-xl leading-none transition">×</button>
                </div>

                <div class="p-5 space-y-4">
                    @if ($blindClose)
                        <div class="bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-xl p-3 text-sm text-amber-900 dark:text-amber-200 flex gap-3">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                            <div>Cierre oculto — solo digita el efectivo que físicamente cuentas en la caja. La diferencia se registrará para auditoría.</div>
                        </div>
                    @else
                        <div class="space-y-2 bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600 dark:text-gray-400">Apertura</span>
                                <span class="font-medium">${{ number_format((float) $session->opening_amount, 0, ',', '.') }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600 dark:text-gray-400">+ Recibido en efectivo</span>
                                <span class="font-medium">${{ number_format($sessionTotals['cash_received'], 0, ',', '.') }}</span>
                            </div>
                            <div class="flex justify-between text-base font-bold pt-2 border-t border-gray-200 dark:border-gray-700">
                                <span>Esperado en caja</span>
                                <span class="text-emerald-600 dark:text-emerald-400">${{ number_format($sessionTotals['closing_expected'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wider text-gray-500">
                            {{ $blindClose ? 'Efectivo contado' : 'Monto contado físicamente' }}
                        </label>
                        <div class="relative mt-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">$</span>
                            <input type="number" step="0.01" min="0"
                                   wire:model.live="closingCounted" placeholder="0"
                                   class="w-full pl-7 pr-3 py-2.5 text-base font-semibold rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-emerald-500" />
                        </div>
                    </div>

                    {{-- Diferencia visible solo si NO es blind --}}
                    @if (! $blindClose)
                        @php
                            $diff = (float) ($closingCounted ?? 0) - (float) $sessionTotals['closing_expected'];
                        @endphp
                        <div class="rounded-xl p-3 text-center {{ abs($diff) < 0.01 ? 'bg-emerald-50 dark:bg-emerald-950/40' : ($diff > 0 ? 'bg-cyan-50 dark:bg-cyan-950/40' : 'bg-rose-50 dark:bg-rose-950/40') }}">
                            <div class="text-[10px] uppercase font-semibold {{ abs($diff) < 0.01 ? 'text-emerald-600' : ($diff > 0 ? 'text-cyan-600' : 'text-rose-600') }}">
                                {{ abs($diff) < 0.01 ? 'Cuadre exacto' : ($diff > 0 ? 'Sobrante' : 'Faltante') }}
                            </div>
                            <div class="text-xl font-bold mt-0.5 {{ abs($diff) < 0.01 ? 'text-emerald-700 dark:text-emerald-300' : ($diff > 0 ? 'text-cyan-700 dark:text-cyan-300' : 'text-rose-700 dark:text-rose-300') }}">
                                {{ $diff >= 0 ? '+' : '' }}${{ number_format($diff, 0, ',', '.') }}
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wider text-gray-500">Notas (opcional)</label>
                        <textarea wire:model="closingNotes" rows="2" placeholder="Observaciones del turno..."
                                  class="w-full mt-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
                    </div>
                </div>

                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2 bg-gray-50 dark:bg-gray-950/50">
                    <button type="button" wire:click="$set('showCloseSessionModal', false)"
                            class="px-5 py-2.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                        Cancelar
                    </button>
                    <button type="button" wire:click="closeCashSession"
                            class="px-6 py-2.5 text-sm font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-md transition">
                        Confirmar cierre
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
