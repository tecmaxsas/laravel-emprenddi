{{-- Estilos de marca compartidos por los 3 paneles Filament.
     El topbar y el sidebar-header de Filament por defecto miden 4rem (h-16)
     y eso recortaba el logo a un tamaño minúsculo. Subimos la altura
     mínima y permitimos que el <img> del logo crezca hasta 3.25rem. --}}
<style>
    /* Topbar (modo sidebar colapsado / mobile) — más alto para acomodar el logo */
    .fi-topbar nav,
    .fi-topbar > nav {
        min-height: 5rem;
    }

    /* Header del sidebar (donde vive el logo en desktop) */
    .fi-sidebar-header {
        min-height: 5rem !important;
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
    }

    /* El logo en sí — sobreescribe el height inline que Filament pone vía brandLogoHeight */
    .fi-logo,
    .fi-sidebar-header img,
    .fi-topbar img.fi-logo,
    .fi-topbar a img,
    .fi-sidebar-header a img {
        height: 3.25rem !important;
        max-height: 3.25rem !important;
        width: auto !important;
        max-width: 100%;
        object-fit: contain;
    }
</style>
