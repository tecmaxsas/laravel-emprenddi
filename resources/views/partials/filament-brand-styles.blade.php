{{-- Estilos de marca compartidos por los 3 paneles Filament.
     El topbar y el sidebar-header de Filament por defecto miden 4rem (h-16)
     y los SVG del logo tienen viewBox 2:1 con bastante whitespace interno,
     así que subimos altura agresivamente y aplicamos transform:scale() para
     que el contenido útil del SVG llene el espacio del header. --}}
<style>
    /* Topbar (modo sidebar colapsado / mobile) — alto para acomodar el logo */
    .fi-topbar nav,
    .fi-topbar > nav {
        min-height: 5.5rem;
    }

    /* Header del sidebar (donde vive el logo en desktop) */
    .fi-sidebar-header {
        min-height: 5.5rem !important;
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        overflow: visible;
    }

    /* Contenedor del logo — permitimos overflow para que el scale no se recorte */
    .fi-sidebar-header > a,
    .fi-topbar a[href][aria-label],
    .fi-logo {
        overflow: visible !important;
    }

    /* El logo en sí — alto generoso + scale para compensar el padding interno
       del SVG (viewBox 1536x768 con margen blanco grande alrededor). */
    .fi-logo,
    .fi-sidebar-header img,
    .fi-topbar img.fi-logo,
    .fi-topbar a img,
    .fi-sidebar-header a img {
        height: 4.5rem !important;
        max-height: 4.5rem !important;
        width: auto !important;
        max-width: none !important;
        object-fit: contain;
        transform: scale(1.35);
        transform-origin: left center;
    }
</style>
