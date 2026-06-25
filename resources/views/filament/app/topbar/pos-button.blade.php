@php
    // Decide a qué POS apunta el botón segun los modulos activos de la
    // empresa y los permisos del usuario. Orden de prioridad:
    //  1. Restaurante (si esta activo + restaurant.use)
    //  2. Parqueadero (si esta activo + parking.use) — gana sobre POS
    //     regular porque el Terminal ES su POS. Si necesitan POS retail
    //     tambien, lo acceden desde el sidebar.
    //  3. POS regular (si tiene pos.use)
    //  4. Si no puede usar ninguno, no rendereamos nada.
    $user = auth()->user();
    $canRestaurant = \App\Support\ModuleGate::active('restaurant')
        && $user?->can('restaurant.use');
    $canParking = \App\Support\ModuleGate::active('parking')
        && $user?->can('parking.use');
    $canRegular = $user?->can('pos.use');

    if ($canRestaurant) {
        $href = route('filament.app.pages.restaurant-pos');
        $title = 'Abrir POS Restaurante';
        $label = 'POS';
    } elseif ($canParking) {
        $href = route('filament.app.pages.parking');
        $title = 'Abrir Terminal de Parqueadero';
        $label = 'Terminal';
    } elseif ($canRegular) {
        $href = route('filament.app.pages.pos');
        $title = 'Abrir terminal POS';
        $label = 'POS';
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
        <span>{{ $label }}</span>
    </a>
@endif
