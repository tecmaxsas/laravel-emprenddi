@php
    // Decide a qué POS apunta el botón:
    //  - Si la empresa tiene el módulo 'restaurant' activo Y el usuario tiene
    //    permiso 'restaurant.use', llevamos al POS Restaurante.
    //  - Si no, al POS regular ('pos.use').
    //  - Si el usuario no puede usar NINGÚN POS, no rendereamos nada.
    $user = auth()->user();
    $restaurantActive = \App\Support\ModuleGate::active('restaurant');
    $canRestaurant = $restaurantActive && $user?->can('restaurant.use');
    // Si la empresa tiene restaurant activo, su POS es el restaurante — el
    // POS tradicional queda oculto (canAccess() en PosTerminal tambien lo
    // bloquea). Asi un cajero sin 'restaurant.use' en empresa restaurante
    // simplemente no ve el boton (no lo mandamos a una pagina 403).
    $canRegular = ! $restaurantActive && $user?->can('pos.use');

    if ($canRestaurant) {
        $href = route('filament.app.pages.restaurant-pos');
        $title = 'Abrir POS Restaurante';
    } elseif ($canRegular) {
        $href = route('filament.app.pages.pos');
        $title = 'Abrir terminal POS';
    } else {
        $href = null;
    }
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        class="fi-btn relative inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-pos fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm shadow-sm hover:shadow"
        style="background-color: rgb(16, 185, 129); color: #ffffff; order: -1;"
        onmouseover="this.style.backgroundColor='rgb(5, 150, 105)';"
        onmouseout="this.style.backgroundColor='rgb(16, 185, 129)';"
        title="{{ $title }}"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 00-3-3z" />
        </svg>
        <span>POS</span>
    </a>
@endif
