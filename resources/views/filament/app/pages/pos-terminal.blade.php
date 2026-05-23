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

        .pos-modal-overlay {
            animation: pos-fade-in 180ms ease;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }
        .pos-modal-content {
            animation: pos-slide-up 240ms cubic-bezier(0.16, 1, 0.3, 1);
            background: #ffffff;
            color: #111827;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            width: 100%;
            max-width: 32rem;
        }
        :is(.dark) .pos-modal-content { background: #111827; color: #f3f4f6; }

        .pos-modal-header {
            flex-shrink: 0;
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        :is(.dark) .pos-modal-header { border-bottom-color: #1f2937; }

        .pos-modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .pos-modal-footer {
            flex-shrink: 0;
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        :is(.dark) .pos-modal-footer { background: #030712; border-top-color: #1f2937; }

        /* Botones del footer — siempre legibles */
        .pos-btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 150ms ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pos-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .pos-btn-secondary { background: #f3f4f6; color: #111827; }
        .pos-btn-secondary:hover:not(:disabled) { background: #e5e7eb; }
        :is(.dark) .pos-btn-secondary { background: #1f2937; color: #f3f4f6; }
        :is(.dark) .pos-btn-secondary:hover:not(:disabled) { background: #374151; }
        .pos-btn-primary { background: rgb(99,102,241); color: #ffffff; }
        .pos-btn-primary:hover:not(:disabled) { background: rgb(79,70,229); }
        .pos-btn-success { background: rgb(16,185,129); color: #ffffff; }
        .pos-btn-success:hover:not(:disabled) { background: rgb(5,150,105); }
        .pos-btn-danger { background: rgb(239,68,68); color: #ffffff; }
        .pos-btn-danger:hover:not(:disabled) { background: rgb(220,38,38); }
        .pos-btn-warning { background: rgb(245,158,11); color: #ffffff; }
        .pos-btn-warning:hover:not(:disabled) { background: rgb(217,119,6); }
        .pos-btn-purple { background: rgb(147,51,234); color: #ffffff; }
        .pos-btn-purple:hover:not(:disabled) { background: rgb(126,34,206); }
        .pos-btn-rose { background: rgb(225,29,72); color: #ffffff; }
        .pos-btn-rose:hover:not(:disabled) { background: rgb(190,18,60); }

        /* Botón cerrar (X) del header */
        .pos-modal-close {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: #9ca3af; font-size: 20px; line-height: 1;
            background: transparent; border: none; cursor: pointer;
            transition: all 150ms;
        }
        .pos-modal-close:hover { background: #f3f4f6; color: #4b5563; }
        :is(.dark) .pos-modal-close:hover { background: #1f2937; color: #d1d5db; }

        /* Cards/badges del modal */
        .pos-modal-card {
            background: #f9fafb; border-radius: 12px; padding: 12px;
            border: 1px solid #e5e7eb;
        }
        :is(.dark) .pos-modal-card { background: rgba(31,41,55,0.5); border-color: #1f2937; }

        .pos-stat-label { font-size: 10px; text-transform: uppercase; font-weight: 600; color: #6b7280; letter-spacing: 0.05em; }
        :is(.dark) .pos-stat-label { color: #9ca3af; }
        .pos-stat-value { font-size: 18px; font-weight: 700; color: #111827; margin-top: 2px; }
        :is(.dark) .pos-stat-value { color: #f3f4f6; }

        /* Inputs y selects dentro de modales */
        .pos-input, .pos-select, .pos-textarea {
            width: 100%; padding: 8px 12px; font-size: 14px;
            border-radius: 8px; border: 1px solid #d1d5db;
            background: #ffffff; color: #111827; outline: none;
            transition: border-color 150ms, box-shadow 150ms;
        }
        :is(.dark) .pos-input, :is(.dark) .pos-select, :is(.dark) .pos-textarea {
            background: #111827; color: #f3f4f6; border-color: #374151;
        }
        .pos-input:focus, .pos-select:focus, .pos-textarea:focus {
            border-color: rgb(99,102,241); box-shadow: 0 0 0 3px rgba(99,102,241,0.2);
        }
        .pos-label {
            font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase;
            letter-spacing: 0.05em; margin-bottom: 4px; display: block;
        }
        :is(.dark) .pos-label { color: #9ca3af; }

        /* Banner destacado (total a cobrar, etc.) */
        .pos-banner {
            border-radius: 12px; padding: 16px; text-align: center;
            background: linear-gradient(135deg, rgb(99,102,241), rgb(79,70,229));
            color: #ffffff;
        }
        .pos-banner-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; opacity: 0.9; }
        .pos-banner-value { font-size: 32px; font-weight: 800; letter-spacing: -0.025em; margin-top: 2px; }

        /* Banner inline de error dentro de modales — alto contraste en ambos temas. */
        .pos-error-banner {
            border-radius: 12px; padding: 12px 14px;
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            animation: pos-slide-up 200ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        :is(.dark) .pos-error-banner {
            background: #450a0a; border-color: #7f1d1d; color: #fecaca;
        }

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
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 10px;
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

        .pos-product-info { padding: 6px 8px 8px; display: flex; flex-direction: column; gap: 1px; flex: 1; }
        .pos-product-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 9px; color: #9ca3af; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        :is(.dark) .pos-product-code { color: #6b7280; }
        .pos-product-name { font-size: 11px; font-weight: 500; line-height: 1.2; color: #111827; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; line-clamp: 2; overflow: hidden; min-height: 26px; }
        :is(.dark) .pos-product-name { color: #f3f4f6; }
        .pos-product-card:hover .pos-product-name { color: rgb(79, 70, 229); }
        :is(.dark) .pos-product-card:hover .pos-product-name { color: rgb(165, 180, 252); }
        .pos-product-price { margin-top: auto; padding-top: 3px; font-size: 13px; font-weight: 700; color: #111827; }
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
         style="width:100vw; height:100vh; top:0; left:0; right:0; bottom:0; z-index:50;"
         x-data="{
            posHotkey(e) {
                // No interceptar si el usuario está escribiendo en un input
                const tag = e.target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
                // No interceptar si hay un modal abierto (el modal maneja su propio teclado)
                if (document.querySelector('.pos-modal-content')) return;

                const map = {
                    F1: () => $wire.openPayment('cash'),
                    F2: () => $wire.openPayment('card'),
                    F3: () => $wire.openPayment('transfer'),
                    F4: () => $wire.openPayment('multi'),
                    F5: () => $wire.openPayment('credit'),
                    F6: () => $wire.set('showRecoverModal', true),
                    F7: () => $wire.openRetentionsModal(),
                    F8: () => $wire.openSuspendModal(),
                    F9: () => $wire.set('showCustomerModal', true),
                };
                const fn = map[e.key];
                if (fn) {
                    e.preventDefault();
                    fn();
                }
            }
         }"
         x-on:keydown.window="posHotkey($event)">

        {{-- ============================================================ --}}
        {{-- TOP BAR — theme-aware                                        --}}
        {{-- ============================================================ --}}
        <header class="flex items-center justify-between px-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shadow-sm"
                style="height:56px; min-height:56px; flex: 0 0 56px;">
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
                    <kbd class="hidden lg:inline-block text-[9px] px-1 py-px rounded bg-amber-200 dark:bg-amber-900 text-amber-700 dark:text-amber-300 font-mono leading-none">F6</kbd>
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
                    <kbd class="hidden lg:inline-block text-[9px] px-1 py-px rounded bg-rose-200 dark:bg-rose-900 text-rose-700 dark:text-rose-300 font-mono leading-none">F7</kbd>
                    @if (! empty($retentions))
                        <span class="ml-1 px-1.5 py-0.5 rounded-md bg-rose-500 text-white text-[10px] font-bold leading-none">{{ count($retentions) }}</span>
                    @endif
                </button>
                <button type="button" wire:click="openSuspendModal"
                        @disabled(empty($cart))
                        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-purple-500/10 text-purple-700 dark:text-purple-300 hover:bg-purple-500/20 transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Suspender
                    <kbd class="hidden lg:inline-block text-[9px] px-1 py-px rounded bg-purple-200 dark:bg-purple-900 text-purple-700 dark:text-purple-300 font-mono leading-none">F8</kbd>
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
        {{-- BODY: 3 columnas — overflow-hidden para evitar que crezca y    --}}
        {{-- empuje el footer fuera del viewport                           --}}
        {{-- ============================================================ --}}
        <div class="flex-1 flex min-h-0 overflow-hidden">

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
            <main class="flex-1 min-w-0 min-h-0 flex flex-col overflow-hidden">
                {{-- Búsqueda + auto-add por escaneo --}}
                <div class="shrink-0 px-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="relative max-w-2xl">
                        <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                        <input type="text"
                               id="pos-search-input"
                               wire:model.live.debounce.250ms="productSearch"
                               x-on:keydown.enter.prevent="$wire.addByBarcode($event.target.value)"
                               placeholder="Buscar por código, nombre o escanear código de barras (Enter para agregar)…"
                               autocomplete="off"
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
            <aside class="w-[30rem] shrink-0 min-h-0 bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col">
                {{-- Cliente — shrink-0 para que NO ceda espacio al carrito --}}
                <div class="shrink-0 p-3 border-b border-gray-200 dark:border-gray-800 flex items-center gap-2">
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
                            class="h-8 px-2 rounded-lg bg-primary-500 hover:bg-primary-600 text-white flex items-center gap-1 shadow-sm transition"
                            title="Cambiar cliente (F9)">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        <kbd class="hidden lg:inline-block text-[9px] px-1 py-px rounded bg-white/30 text-white font-mono leading-none">F9</kbd>
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
                                    <div class="grid grid-cols-12 gap-2 items-end">
                                        {{-- Cantidad: 4 cols, controles más grandes para touch --}}
                                        <div class="col-span-4">
                                            <div class="text-[10px] text-gray-400 mb-1 font-semibold uppercase tracking-wide">Cantidad</div>
                                            <div class="flex items-center gap-1">
                                                <button type="button" wire:click="decLine({{ $i }})"
                                                        class="w-7 h-8 shrink-0 rounded-md bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-base font-bold transition active:scale-90">−</button>
                                                <input type="number" step="0.01" min="0"
                                                       wire:model.live.blur="cart.{{ $i }}.quantity"
                                                       class="flex-1 min-w-0 text-center text-sm font-semibold rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-1 py-1.5 outline-none focus:ring-2 focus:ring-primary-500" />
                                                <button type="button" wire:click="incLine({{ $i }})"
                                                        class="w-7 h-8 shrink-0 rounded-md bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-base font-bold transition active:scale-90">+</button>
                                            </div>
                                        </div>

                                        {{-- Precio: 3 cols --}}
                                        <div class="col-span-3">
                                            <div class="text-[10px] text-gray-400 mb-1 font-semibold uppercase tracking-wide">Precio</div>
                                            @if ($posSettings['allow_price_modification'])
                                                <input type="number" step="0.01" min="0"
                                                       wire:model.live.blur="cart.{{ $i }}.unit_price"
                                                       class="w-full text-right text-xs rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1.5 outline-none focus:ring-2 focus:ring-primary-500" />
                                            @else
                                                <div class="w-full text-right text-xs px-2 py-1.5 text-gray-700 dark:text-gray-300 font-medium">
                                                    ${{ number_format($line['unit_price'], 0, ',', '.') }}
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Descuento % por línea: 2 cols --}}
                                        @if ($posSettings['allow_discount'])
                                            <div class="col-span-2">
                                                <div class="text-[10px] text-gray-400 mb-1 font-semibold uppercase tracking-wide" title="Descuento de esta línea (%)">Desc %</div>
                                                <input type="number" step="0.5" min="0" max="100"
                                                       value="{{ rtrim(rtrim(number_format((float) ($line['discount_percentage_manual'] ?? 0), 2, '.', ''), '0'), '.') ?: '0' }}"
                                                       wire:change="setLineDiscountPct({{ $i }}, $event.target.value)"
                                                       class="w-full text-right text-xs rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-1 py-1.5 outline-none focus:ring-2 focus:ring-amber-500"
                                                       placeholder="0" />
                                            </div>
                                        @endif

                                        {{-- Subtotal: 3 cols, prominente --}}
                                        <div class="{{ $posSettings['allow_discount'] ? 'col-span-3' : 'col-span-5' }} text-right">
                                            <div class="text-[10px] text-gray-400 mb-1 font-semibold uppercase tracking-wide">Subtotal</div>
                                            <div class="text-base font-bold text-primary-600 dark:text-primary-400 whitespace-nowrap py-1">
                                                ${{ number_format($line['total'], 0, ',', '.') }}
                                            </div>
                                            @if (! empty($line['discount_amount']) && (float) $line['discount_amount'] > 0)
                                                <div class="text-[10px] text-amber-600 leading-tight">
                                                    −${{ number_format((float) $line['discount_amount'], 0, ',', '.') }} dto.
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Descuento global — visible solo si la feature está habilitada y hay carrito --}}
                @if ($posSettings['allow_discount'] && ! empty($cart))
                    <div class="shrink-0 border-t border-gray-200 dark:border-gray-800 bg-amber-50 dark:bg-amber-950/30">
                        <div class="px-4 py-2.5">
                            <div class="flex items-center justify-between mb-1.5">
                                <div class="text-[10px] text-amber-700 dark:text-amber-400 font-semibold uppercase tracking-wide">Descuento global</div>
                                @if ($cartDiscountPct > 0)
                                    <button type="button" wire:click="clearCartDiscount"
                                            class="text-[10px] text-amber-700 dark:text-amber-400 hover:underline">Quitar</button>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="flex rounded-md border border-amber-300 dark:border-amber-700 overflow-hidden text-xs font-semibold">
                                    <button type="button"
                                            wire:click="$set('cartDiscountMode', 'pct')"
                                            class="px-2 py-1 {{ $cartDiscountMode === 'pct' ? 'bg-amber-500 text-white' : 'bg-white dark:bg-gray-900 text-amber-700 dark:text-amber-400' }}">%</button>
                                    <button type="button"
                                            wire:click="$set('cartDiscountMode', 'amount')"
                                            class="px-2 py-1 {{ $cartDiscountMode === 'amount' ? 'bg-amber-500 text-white' : 'bg-white dark:bg-gray-900 text-amber-700 dark:text-amber-400' }}">$</button>
                                </div>
                                <input type="number" step="0.01" min="0"
                                       wire:change="setCartDiscount('{{ $cartDiscountMode }}', $event.target.value)"
                                       value="{{ $cartDiscountMode === 'pct'
                                           ? (rtrim(rtrim(number_format($cartDiscountPct, 2, '.', ''), '0'), '.') ?: '0')
                                           : (int) $cartDiscountAmount }}"
                                       class="flex-1 text-right text-xs rounded-md border border-amber-300 dark:border-amber-700 bg-white dark:bg-gray-900 px-2 py-1.5 outline-none focus:ring-2 focus:ring-amber-500"
                                       placeholder="0" />
                                <div class="flex gap-1">
                                    @foreach ([5, 10, 20] as $quick)
                                        <button type="button"
                                                wire:click="setCartDiscount('pct', {{ $quick }})"
                                                class="px-2 py-1.5 text-[10px] font-semibold rounded-md bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-900/60 transition">{{ $quick }}%</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Totales — shrink-0 para que SIEMPRE se vean al fondo del cart --}}
                <div class="shrink-0 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950">
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
                                <span>Descuento{{ $cartDiscountPct > 0 ? ' (incl. '.rtrim(rtrim(number_format($cartDiscountPct, 2, '.', ''), '0'), '.').'% global)' : '' }}</span>
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
        {{-- BARRA INFERIOR — siempre visible al fondo del viewport        --}}
        {{-- ============================================================ --}}
        <footer class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 flex items-stretch shadow-[0_-1px_3px_rgba(0,0,0,0.04)]"
                style="height:64px; min-height:64px; flex: 0 0 64px;">
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
                    <span class="text-xs font-semibold flex items-center gap-1">Efectivo <kbd class="hidden md:inline-block text-[9px] px-1 py-px rounded bg-gray-100 dark:bg-gray-800 text-gray-500 font-mono leading-none">F1</kbd></span>
                </button>
                <button type="button" wire:click="openPayment('card')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-blue-50 dark:hover:bg-blue-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-xs font-semibold flex items-center gap-1">Tarjeta <kbd class="hidden md:inline-block text-[9px] px-1 py-px rounded bg-gray-100 dark:bg-gray-800 text-gray-500 font-mono leading-none">F2</kbd></span>
                </button>
                <button type="button" wire:click="openPayment('transfer')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span class="text-xs font-semibold flex items-center gap-1">Transferencia <kbd class="hidden md:inline-block text-[9px] px-1 py-px rounded bg-gray-100 dark:bg-gray-800 text-gray-500 font-mono leading-none">F3</kbd></span>
                </button>
                <button type="button" wire:click="openPayment('multi')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-purple-50 dark:hover:bg-purple-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    <span class="text-xs font-semibold flex items-center gap-1">Multi-pago <kbd class="hidden md:inline-block text-[9px] px-1 py-px rounded bg-gray-100 dark:bg-gray-800 text-gray-500 font-mono leading-none">F4</kbd></span>
                </button>
                <button type="button" wire:click="openPayment('credit')"
                        @disabled(empty($cart))
                        class="flex-1 flex flex-col items-center justify-center gap-0.5 hover:bg-amber-50 dark:hover:bg-amber-950/40 disabled:opacity-30 disabled:cursor-not-allowed transition border-l border-gray-200 dark:border-gray-800">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-xs font-semibold flex items-center gap-1">A crédito <kbd class="hidden md:inline-block text-[9px] px-1 py-px rounded bg-gray-100 dark:bg-gray-800 text-gray-500 font-mono leading-none">F5</kbd></span>
                </button>
            </div>
        </footer>
    </div>
    @endif {{-- end @else hasSession --}}

    {{-- ================================================================ --}}
    {{-- MODAL DE COBRO                                                    --}}
    {{-- ================================================================ --}}
    @if ($showPaymentModal)
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="closePaymentModal">
            <div class="pos-modal-content">
                {{-- Header --}}
                <div class="pos-modal-header">
                    <h2 class="text-base font-semibold flex-1">
                        @switch($paymentMode)
                            @case('cash') Cobro en efectivo @break
                            @case('card') Cobro con tarjeta @break
                            @case('transfer') Cobro por transferencia @break
                            @case('multi') Pago múltiple @break
                            @case('credit') Venta a crédito @break
                        @endswitch
                    </h2>
                    <button type="button" wire:click="closePaymentModal" class="pos-modal-close">×</button>
                </div>

                <div class="pos-modal-body">
                    @if ($paymentError)
                        <div class="pos-error-banner" role="alert">
                            <div style="display:flex; gap:10px; align-items:flex-start;">
                                <span style="font-size:18px; line-height:1;">⚠️</span>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-weight:600; font-size:13px; margin-bottom:2px;">No se pudo procesar la venta</div>
                                    <div style="font-size:12px; line-height:1.45; word-break:break-word;">{{ $paymentError }}</div>
                                </div>
                                <button type="button" wire:click="$set('paymentError', null)"
                                        style="background:transparent; border:0; cursor:pointer; font-size:18px; line-height:1; padding:0 4px; color:inherit; opacity:0.7;">×</button>
                            </div>
                        </div>
                    @endif

                    {{-- Total grande — net_payable cuando hay retenciones --}}
                    <div class="pos-banner">
                        <div class="pos-banner-label">
                            {{ $totals['retentions'] > 0 ? 'Neto a cobrar (con retenciones)' : 'Total a cobrar' }}
                        </div>
                        <div class="pos-banner-value">${{ number_format($totals['net_payable'], 0, ',', '.') }}</div>
                        @if ($totals['retentions'] > 0)
                            <div style="font-size:11px; opacity:0.9; margin-top:4px;">
                                Total factura ${{ number_format($totals['total'], 0, ',', '.') }} − retenciones ${{ number_format($totals['retentions'], 0, ',', '.') }}
                            </div>
                        @endif
                    </div>

                    @if ($paymentMode === 'credit')
                        <div style="border-radius:12px; padding:16px; background:#fef3c7; border:1px solid #fde68a; color:#78350f; font-size:14px;"
                             class="dark:!bg-amber-950 dark:!text-amber-200">
                            La factura quedará pendiente de pago. El cliente puede pagar después desde la vista detallada de la factura.
                        </div>
                    @else
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            @foreach ($payments as $i => $p)
                                <div class="pos-modal-card" style="display:flex; flex-direction:column; gap:8px;">
                                    <div style="display:grid; grid-template-columns: 5fr 6fr 1fr; gap:8px; align-items:center;">
                                        <select wire:model.live="payments.{{ $i }}.payment_method" class="pos-select">
                                            @foreach ($this->paymentMethods as $code => $label)
                                                <option value="{{ $code }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input type="number" step="0.01" min="0"
                                               wire:model.live.blur="payments.{{ $i }}.amount"
                                               placeholder="0"
                                               class="pos-input"
                                               style="text-align:right; font-weight:600; font-size:15px;" />
                                        <button type="button" wire:click="removePayment({{ $i }})" class="pos-modal-close" title="Quitar">×</button>
                                    </div>
                                    @if (in_array($p['payment_method'] ?? '', ['bank_transfer', 'check', 'electronic'], true))
                                        <input type="text" placeholder="Referencia / Voucher / Comprobante"
                                               wire:model.blur="payments.{{ $i }}.reference"
                                               class="pos-input" style="font-size:12px;" />
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($paymentMode === 'multi')
                            <button type="button" wire:click="addPaymentLine"
                                    style="width:100%; padding:10px; font-size:14px; font-weight:500; border-radius:12px; border:2px dashed #d1d5db; background:transparent; cursor:pointer; color:#6b7280; transition:all 150ms;"
                                    onmouseover="this.style.borderColor='rgb(99,102,241)'; this.style.color='rgb(99,102,241)';"
                                    onmouseout="this.style.borderColor='#d1d5db'; this.style.color='#6b7280';">
                                + Añadir método de pago
                            </button>
                        @endif

                        {{-- Resumen --}}
                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px;">
                            <div class="pos-modal-card" style="text-align:center;">
                                <div class="pos-stat-label">Pagado</div>
                                <div class="pos-stat-value">${{ number_format($totals['paid'], 0, ',', '.') }}</div>
                            </div>
                            <div class="pos-modal-card" style="text-align:center; {{ $totals['remaining'] > 0 ? 'background:#fef3c7; border-color:#fde68a;' : 'background:#d1fae5; border-color:#a7f3d0;' }}">
                                <div class="pos-stat-label" style="{{ $totals['remaining'] > 0 ? 'color:#a16207;' : 'color:#047857;' }}">
                                    {{ $totals['remaining'] > 0 ? 'Falta' : 'Cubierto' }}
                                </div>
                                <div class="pos-stat-value" style="{{ $totals['remaining'] > 0 ? 'color:#854d0e;' : 'color:#065f46;' }}">
                                    ${{ number_format($totals['remaining'], 0, ',', '.') }}
                                </div>
                            </div>
                            <div class="pos-modal-card" style="text-align:center;">
                                <div class="pos-stat-label">Vuelto</div>
                                <div class="pos-stat-value" style="{{ $totals['change'] > 0 ? 'color:#059669;' : '' }}">
                                    ${{ number_format($totals['change'], 0, ',', '.') }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Tipo de factura: POS o Electrónica --}}
                <div style="display:flex; gap:8px; align-items:center; padding:10px 0; border-top:1px solid #e5e7eb;">
                    <span style="font-size:13px; font-weight:600; color:#374151;">Tipo de factura:</span>
                    <button type="button" wire:click="$set('invoiceKind', 'pos')"
                            style="padding:6px 14px; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer; border:1px solid {{ $invoiceKind === 'pos' ? '#6366f1' : '#d1d5db' }}; background:{{ $invoiceKind === 'pos' ? '#6366f1' : '#ffffff' }}; color:{{ $invoiceKind === 'pos' ? '#ffffff' : '#374151' }};">
                        🧾 POS
                    </button>
                    <button type="button" wire:click="$set('invoiceKind', 'electronic')"
                            style="padding:6px 14px; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer; border:1px solid {{ $invoiceKind === 'electronic' ? '#6366f1' : '#d1d5db' }}; background:{{ $invoiceKind === 'electronic' ? '#6366f1' : '#ffffff' }}; color:{{ $invoiceKind === 'electronic' ? '#ffffff' : '#374151' }};">
                        📡 Electrónica (DIAN)
                    </button>
                </div>

                <div class="pos-modal-footer">
                    <button type="button" wire:click="closePaymentModal" class="pos-btn pos-btn-secondary">Cancelar</button>
                    <button type="button" wire:click="processSale" wire:loading.attr="disabled" class="pos-btn pos-btn-success">
                        <span wire:loading.remove wire:target="processSale">✓ Confirmar venta</span>
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
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="$set('showSuspendModal', false)">
            <div class="pos-modal-content" style="max-width: 28rem;">
                <div class="pos-modal-header">
                    <h2 class="text-base font-semibold flex-1">Suspender venta</h2>
                    <button type="button" wire:click="$set('showSuspendModal', false)" class="pos-modal-close">×</button>
                </div>
                <div class="pos-modal-body">
                    <p style="font-size:14px; color:#6b7280;" class="dark:!text-gray-400">
                        El carrito actual se guardará como venta suspendida y se vaciará. Podrás recuperarla cuando quieras.
                    </p>
                    <div>
                        <label class="pos-label">Etiqueta (opcional)</label>
                        <input type="text" wire:model="suspendName" placeholder="Mesa 5, María Pérez, etc." class="pos-input" />
                    </div>
                    <div class="pos-modal-card" style="display:flex; justify-content:space-between; font-size:14px;">
                        <span style="color:#6b7280;">Items: <strong style="color:#111827;" class="dark:!text-gray-100">{{ $totals['items'] }}</strong></span>
                        <span style="color:#6b7280;">Total: <strong style="color:#111827;" class="dark:!text-gray-100">${{ number_format($totals['total'], 0, ',', '.') }}</strong></span>
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <button type="button" wire:click="$set('showSuspendModal', false)" class="pos-btn pos-btn-secondary">Cancelar</button>
                    <button type="button" wire:click="suspendSale" class="pos-btn pos-btn-purple">Suspender</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL — RECUPERAR VENTA                                           --}}
    {{-- ================================================================ --}}
    @if ($showRecoverModal)
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="$set('showRecoverModal', false)">
            <div class="pos-modal-content" style="max-width: 42rem;">
                <div class="pos-modal-header">
                    <h2 class="text-base font-semibold flex-1">Ventas suspendidas</h2>
                    <button type="button" wire:click="$set('showRecoverModal', false)" class="pos-modal-close">×</button>
                </div>
                <div class="pos-modal-body">
                    @if ($this->suspendedSales->isEmpty())
                        <div style="padding:48px; text-align:center; font-size:14px; color:#9ca3af;">
                            No hay ventas suspendidas en esta sede.
                        </div>
                    @else
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            @foreach ($this->suspendedSales as $s)
                                <div class="pos-modal-card" style="display:flex; align-items:center; gap:12px;">
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-size:14px; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $s->name }}</div>
                                        <div style="font-size:12px; color:#6b7280; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            {{ $s->customer?->name ?? '—' }} · {{ $s->seller?->name ?? $s->seller?->email ?? '?' }} · {{ $s->created_at->format('Y-m-d H:i') }}
                                        </div>
                                    </div>
                                    <div style="text-align:right; flex-shrink:0;">
                                        <div class="pos-stat-label">{{ $s->items_count }} items</div>
                                        <div class="pos-stat-value" style="font-size:15px;">${{ number_format((float) $s->total, 0, ',', '.') }}</div>
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:4px; flex-shrink:0;">
                                        <button type="button" wire:click="recoverSale({{ $s->id }})" class="pos-btn pos-btn-success" style="padding:6px 12px; font-size:12px;">Recuperar</button>
                                        <button type="button" wire:click="deleteSuspendedSale({{ $s->id }})" wire:confirm="¿Eliminar esta venta suspendida?"
                                                style="padding:4px 12px; font-size:11px; border-radius:6px; color:rgb(239,68,68); background:transparent; border:none; cursor:pointer;">
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
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="$set('showRetentionsModal', false)">
            <div class="pos-modal-content" style="max-width: 42rem;">
                <div class="pos-modal-header">
                    <div style="flex:1;">
                        <h2 class="text-base font-semibold">Retenciones aplicables</h2>
                        <p style="font-size:11px; color:#6b7280; margin-top:2px;">Aplican cuando el cliente es agente retenedor (Gran Contribuyente, Estado). Para venta a consumidor final, deja la lista vacía.</p>
                    </div>
                    <button type="button" wire:click="$set('showRetentionsModal', false)" class="pos-modal-close">×</button>
                </div>

                <div class="pos-modal-body">
                    {{-- Catálogo --}}
                    <div>
                        <div class="pos-stat-label" style="margin-bottom:8px;">Disponibles</div>
                        @if ($this->availableRetentionTaxes->isEmpty())
                            <div class="pos-modal-card" style="font-size:13px; color:#9ca3af; font-style:italic;">
                                No hay impuestos de retención configurados. Configúralos en Contabilidad → Impuestos.
                            </div>
                        @else
                            <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                                @foreach ($this->availableRetentionTaxes as $rt)
                                    @php
                                        $alreadyApplied = collect($retentions)->contains(fn ($r) => (int) $r['tax_id'] === (int) $rt->id);
                                    @endphp
                                    <button type="button"
                                            wire:click="addRetention({{ $rt->id }})"
                                            @disabled($alreadyApplied)
                                            class="pos-modal-card"
                                            style="text-align:left; cursor:pointer; transition:all 150ms; {{ $alreadyApplied ? 'opacity:0.4; cursor:not-allowed;' : '' }}"
                                            onmouseover="if(!this.disabled){this.style.borderColor='rgb(225,29,72)'}"
                                            onmouseout="this.style.borderColor=''">
                                        <div style="font-family:monospace; font-size:11px; color:#6b7280;">{{ $rt->code }}</div>
                                        <div style="font-size:14px; font-weight:600;">{{ $rt->name }}</div>
                                        <div style="font-size:11px; color:#6b7280;">{{ number_format((float) $rt->rate, 4) }} %</div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Aplicadas --}}
                    @if (! empty($retentions))
                        <div>
                            <div class="pos-stat-label" style="margin-bottom:8px;">Aplicadas a esta venta</div>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                @foreach ($retentions as $i => $r)
                                    <div class="pos-modal-card" style="display:grid; grid-template-columns: 5fr 3fr 3fr 1fr; gap:8px; align-items:center; background:#fef2f2; border-color:#fecaca;">
                                        <div style="min-width:0;">
                                            <div style="font-family:monospace; font-size:11px; color:#6b7280;">{{ $r['tax_code'] ?? '' }}</div>
                                            <div style="font-size:13px; font-weight:600; overflow:hidden; text-overflow:ellipsis;">{{ $r['tax_name'] ?? '' }}</div>
                                            <div style="font-size:11px; color:#6b7280;">{{ number_format((float) $r['rate'], 4) }} %</div>
                                        </div>
                                        <div>
                                            <div class="pos-stat-label" style="margin-bottom:4px;">Base</div>
                                            <input type="number" step="0.01" min="0"
                                                   wire:model.live.blur="retentions.{{ $i }}.base_amount"
                                                   class="pos-input" style="font-size:12px; padding:4px 8px; text-align:right;" />
                                        </div>
                                        <div style="text-align:right;">
                                            <div class="pos-stat-label" style="margin-bottom:4px;">Retenido</div>
                                            <div style="font-size:14px; font-weight:700; color:rgb(225,29,72); white-space:nowrap;">
                                                ${{ number_format((float) ($r['amount'] ?? 0), 0, ',', '.') }}
                                            </div>
                                        </div>
                                        <div style="text-align:right;">
                                            <button type="button" wire:click="removeRetention({{ $i }})" class="pos-modal-close" title="Quitar">×</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Resumen --}}
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px;">
                        <div class="pos-modal-card" style="text-align:center;">
                            <div class="pos-stat-label">Total factura</div>
                            <div class="pos-stat-value">${{ number_format($totals['total'], 0, ',', '.') }}</div>
                        </div>
                        <div class="pos-modal-card" style="text-align:center; background:#fef2f2; border-color:#fecaca;">
                            <div class="pos-stat-label" style="color:#be123c;">Retenciones</div>
                            <div class="pos-stat-value" style="color:#9f1239;">−${{ number_format($totals['retentions'], 0, ',', '.') }}</div>
                        </div>
                        <div class="pos-modal-card" style="text-align:center; background:#d1fae5; border-color:#a7f3d0;">
                            <div class="pos-stat-label" style="color:#047857;">Neto a pagar</div>
                            <div class="pos-stat-value" style="color:#065f46;">${{ number_format($totals['net_payable'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                <div class="pos-modal-footer">
                    <button type="button" wire:click="$set('showRetentionsModal', false)" class="pos-btn pos-btn-rose">Listo</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL — DETALLES DE CAJA                                          --}}
    {{-- ================================================================ --}}
    @if ($showSessionDetailsModal && $session && $sessionTotals)
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="$set('showSessionDetailsModal', false)">
            <div class="pos-modal-content" style="max-width: 28rem;">
                <div class="pos-modal-header">
                    <div style="flex:1;">
                        <h2 class="text-base font-semibold">Caja en curso</h2>
                        <p style="font-size:11px; color:#6b7280; margin-top:2px;">Abierta {{ $session->opened_at->diffForHumans() }} · {{ $session->location?->name }}</p>
                    </div>
                    <button type="button" wire:click="$set('showSessionDetailsModal', false)" class="pos-modal-close">×</button>
                </div>

                <div class="pos-modal-body">
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px;">
                        <div class="pos-modal-card">
                            <div class="pos-stat-label">Apertura</div>
                            <div class="pos-stat-value">${{ number_format((float) $session->opening_amount, 0, ',', '.') }}</div>
                        </div>
                        <div class="pos-modal-card">
                            <div class="pos-stat-label">Ventas</div>
                            <div class="pos-stat-value">{{ $sessionTotals['sales']['count'] }}</div>
                        </div>
                        <div class="pos-modal-card">
                            <div class="pos-stat-label">Egresos</div>
                            <div class="pos-stat-value">{{ $sessionTotals['purchases']['count'] + $sessionTotals['expenses']['count'] }}</div>
                        </div>
                    </div>

                    {{-- INGRESOS (ventas) por método --}}
                    <div>
                        <div class="pos-stat-label" style="margin-bottom:8px;">Ingresos por método</div>
                        @if (empty($sessionTotals['sales']['by_method']))
                            <div style="font-size:13px; color:#9ca3af; font-style:italic; padding:8px 12px;">Sin cobros aún.</div>
                        @else
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                @foreach ($sessionTotals['sales']['by_method'] as $method => $amount)
                                    <div class="pos-modal-card" style="display:flex; justify-content:space-between; padding:8px 12px;">
                                        <span style="font-size:14px;">{{ \App\Models\Payment::PAYMENT_METHODS[$method] ?? $method }}</span>
                                        <span style="font-size:14px; font-weight:600; color:rgb(5,150,105);">+${{ number_format($amount, 0, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- EGRESOS (compras + gastos) por método --}}
                    @php
                        $egresosMerged = [];
                        foreach (($sessionTotals['purchases']['by_method'] ?? []) as $m => $a) {
                            $egresosMerged[$m] = ($egresosMerged[$m] ?? 0) + $a;
                        }
                        foreach (($sessionTotals['expenses']['by_method'] ?? []) as $m => $a) {
                            $egresosMerged[$m] = ($egresosMerged[$m] ?? 0) + $a;
                        }
                    @endphp
                    @if (! empty($egresosMerged))
                        <div>
                            <div class="pos-stat-label" style="margin-bottom:8px;">Egresos por método (compras + gastos)</div>
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                @foreach ($egresosMerged as $method => $amount)
                                    <div class="pos-modal-card" style="display:flex; justify-content:space-between; padding:8px 12px;">
                                        <span style="font-size:14px;">{{ \App\Models\Payment::PAYMENT_METHODS[$method] ?? $method }}</span>
                                        <span style="font-size:14px; font-weight:600; color:rgb(220,38,38);">−${{ number_format($amount, 0, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div style="display:flex; flex-direction:column; gap:8px; padding-top:12px; border-top:1px solid #e5e7eb;" class="dark:!border-gray-800">
                        <div style="display:flex; justify-content:space-between; font-size:14px;">
                            <span style="color:#6b7280;">Total ventas</span>
                            <span style="font-weight:600;">${{ number_format($sessionTotals['sales']['total'], 0, ',', '.') }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:14px;">
                            <span style="color:#6b7280;">Total compras + gastos</span>
                            <span style="font-weight:600; color:rgb(220,38,38);">${{ number_format($sessionTotals['purchases']['total'] + $sessionTotals['expenses']['total'], 0, ',', '.') }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:14px;">
                            <span style="color:#6b7280;">Neto en efectivo del turno</span>
                            <span style="font-weight:600;">{{ $sessionTotals['net_cash'] >= 0 ? '+' : '' }}${{ number_format($sessionTotals['net_cash'], 0, ',', '.') }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:700; padding-top:8px; border-top:1px solid #e5e7eb;" class="dark:!border-gray-700">
                            <span>Esperado en caja</span>
                            <span style="color:rgb(5,150,105);">${{ number_format($sessionTotals['expected_cash'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>

                <div class="pos-modal-footer">
                    <button type="button" wire:click="$set('showSessionDetailsModal', false)" class="pos-btn pos-btn-primary">Cerrar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL — CIERRE DE CAJA (respeta blind_cash_close)                 --}}
    {{-- ================================================================ --}}
    @if ($showCloseSessionModal && $session && $sessionTotals)
        @php $blindClose = (bool) ($posSettings['blind_cash_close'] ?? false); @endphp
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="$set('showCloseSessionModal', false)">
            <div class="pos-modal-content" style="max-width: 28rem;">
                <div class="pos-modal-header">
                    <div style="flex:1;">
                        <h2 class="text-base font-semibold">Cierre de caja</h2>
                        <p style="font-size:11px; color:#6b7280; margin-top:2px;">{{ $session->location?->name }} · turno desde {{ $session->opened_at->format('Y-m-d H:i') }}</p>
                    </div>
                    <button type="button" wire:click="$set('showCloseSessionModal', false)" class="pos-modal-close">×</button>
                </div>

                <div class="pos-modal-body">
                    @if ($blindClose)
                        <div style="border-radius:12px; padding:12px; background:#fef3c7; border:1px solid #fde68a; color:#78350f; font-size:13px;">
                            <strong>Cierre oculto:</strong> solo digita el efectivo que físicamente cuentas en la caja. La diferencia se registrará para auditoría.
                        </div>
                    @else
                        @php
                            $egresosCashTotal = (float) ($sessionTotals['purchases']['cash'] ?? 0)
                                              + (float) ($sessionTotals['expenses']['cash'] ?? 0);
                        @endphp
                        <div class="pos-modal-card" style="display:flex; flex-direction:column; gap:8px;">
                            <div style="display:flex; justify-content:space-between; font-size:14px;">
                                <span style="color:#6b7280;">Apertura</span>
                                <span style="font-weight:500;">${{ number_format((float) $session->opening_amount, 0, ',', '.') }}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:14px;">
                                <span style="color:#6b7280;">+ Recibido en efectivo (ventas)</span>
                                <span style="font-weight:500; color:rgb(5,150,105);">+${{ number_format($sessionTotals['sales']['cash'], 0, ',', '.') }}</span>
                            </div>
                            @if ($egresosCashTotal > 0)
                                <div style="display:flex; justify-content:space-between; font-size:14px;">
                                    <span style="color:#6b7280;">− Pagado en efectivo (compras + gastos)</span>
                                    <span style="font-weight:500; color:rgb(220,38,38);">−${{ number_format($egresosCashTotal, 0, ',', '.') }}</span>
                                </div>
                            @endif
                            <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:700; padding-top:8px; border-top:1px solid #e5e7eb;">
                                <span>Esperado en caja</span>
                                <span style="color:rgb(5,150,105);">${{ number_format($sessionTotals['expected_cash'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="pos-label">{{ $blindClose ? 'Efectivo contado' : 'Monto contado físicamente' }}</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af;">$</span>
                            <input type="number" step="0.01" min="0"
                                   wire:model.live="closingCounted" placeholder="0"
                                   class="pos-input"
                                   style="padding-left:28px; font-size:18px; font-weight:600;" />
                        </div>
                    </div>

                    {{-- Diferencia visible solo si NO es blind --}}
                    @if (! $blindClose)
                        @php
                            $diff = (float) ($closingCounted ?? 0) - (float) $sessionTotals['expected_cash'];
                            $isExact = abs($diff) < 0.01;
                            $isSobrante = $diff > 0;
                            $bg = $isExact ? '#d1fae5' : ($isSobrante ? '#cffafe' : '#fee2e2');
                            $bdr = $isExact ? '#a7f3d0' : ($isSobrante ? '#a5f3fc' : '#fecaca');
                            $tcolor = $isExact ? '#047857' : ($isSobrante ? '#0e7490' : '#b91c1c');
                            $tcolorDark = $isExact ? '#065f46' : ($isSobrante ? '#155e75' : '#991b1b');
                        @endphp
                        <div style="border-radius:12px; padding:12px; text-align:center; background:{{ $bg }}; border:1px solid {{ $bdr }};">
                            <div style="font-size:11px; text-transform:uppercase; font-weight:600; color:{{ $tcolor }};">
                                {{ $isExact ? 'Cuadre exacto' : ($isSobrante ? 'Sobrante' : 'Faltante') }}
                            </div>
                            <div style="font-size:22px; font-weight:700; margin-top:2px; color:{{ $tcolorDark }};">
                                {{ $diff >= 0 ? '+' : '' }}${{ number_format($diff, 0, ',', '.') }}
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="pos-label">Notas (opcional)</label>
                        <textarea wire:model="closingNotes" rows="2" placeholder="Observaciones del turno..." class="pos-textarea"></textarea>
                    </div>
                </div>

                <div class="pos-modal-footer">
                    <button type="button" wire:click="$set('showCloseSessionModal', false)" class="pos-btn pos-btn-secondary">Cancelar</button>
                    <button type="button" wire:click="closeCashSession" class="pos-btn pos-btn-success">Confirmar cierre</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- MODAL CLIENTE                                                     --}}
    {{-- ================================================================ --}}
    @if ($showCustomerModal)
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 100;"
             wire:click.self="$set('showCustomerModal', false)">
            <div class="pos-modal-content" style="max-width: 28rem;">
                <div class="pos-modal-header">
                    <h2 class="text-base font-semibold flex-1">Cliente de la venta</h2>
                    <button type="button" wire:click="$set('showCustomerModal', false)" class="pos-modal-close">×</button>
                </div>
                <div class="pos-modal-body">
                    <div>
                        <label class="pos-label">Nombre / razón social</label>
                        <input type="text" wire:model="newCustomerName" placeholder="Juan Pérez Gómez" class="pos-input" />
                    </div>
                    <div>
                        <label class="pos-label">Documento (CC / NIT)</label>
                        <input type="text" wire:model="newCustomerDocument" placeholder="1234567890" class="pos-input" />
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <button type="button" wire:click="$set('showCustomerModal', false)" class="pos-btn pos-btn-secondary">Cancelar</button>
                    <button type="button" wire:click="createQuickCustomer" class="pos-btn pos-btn-primary">Crear y usar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal PIN supervisor para descuento que excede umbral --}}
    @if ($showSupervisorPinModal)
        <div class="fixed inset-0 flex items-center justify-center p-4 pos-modal-overlay"
             style="z-index: 110;"
             wire:click.self="cancelSupervisorPin">
            <div class="pos-modal-content" style="max-width: 26rem;">
                <div class="pos-modal-header" style="background:#fef3c7; color:#92400e;">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <h2 class="text-base font-semibold flex-1">Aprobación de supervisor</h2>
                    <button type="button" wire:click="cancelSupervisorPin" class="pos-modal-close">×</button>
                </div>
                <div class="pos-modal-body">
                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        @if ($pendingDiscount)
                            El descuento {{ $pendingDiscount['type'] === 'cart' ? 'global de la venta' : 'de la línea' }}
                            (<strong>{{ rtrim(rtrim(number_format((float) $pendingDiscount['pct'], 2, '.', ''), '0'), '.') }}%</strong>)
                            excede el límite permitido.
                        @endif
                        <div class="mt-1 text-xs text-gray-500">Ingresa la contraseña de un usuario con permiso para aprobar descuentos.</div>
                    </div>
                    <div>
                        <label class="pos-label">Contraseña del supervisor</label>
                        <input type="password"
                               wire:model="supervisorPin"
                               wire:keydown.enter="approveDiscountWithPin"
                               autofocus
                               class="pos-input" />
                        @if ($supervisorPinError)
                            <div class="text-xs text-rose-600 dark:text-rose-400 mt-1.5">{{ $supervisorPinError }}</div>
                        @endif
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <button type="button" wire:click="cancelSupervisorPin" class="pos-btn pos-btn-secondary">Cancelar</button>
                    <button type="button" wire:click="approveDiscountWithPin" class="pos-btn pos-btn-primary">Aprobar</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
