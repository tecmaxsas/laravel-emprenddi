{{-- Botón "Ayuda" en el topbar del panel App. Si la empresa tiene el
     módulo Parqueadero activo, apunta al centro de ayuda especifico
     (/docs/parqueadero); en cualquier otro caso, al centro general
     (emprenddi.com/docs/). Se abre en una pestaña nueva. --}}
@php
    $docsHref = \App\Support\ModuleGate::active('parking')
        ? route('docs.parking')
        : 'https://emprenddi.com/docs/';
    $docsTitle = \App\Support\ModuleGate::active('parking')
        ? 'Abrir guia del modulo Parqueadero en nueva pestana'
        : 'Abrir centro de ayuda en nueva pestana';
@endphp
<a
    href="{{ $docsHref }}"
    target="_blank"
    rel="noopener noreferrer"
    class="fi-btn relative inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-docs fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm shadow-sm hover:shadow"
    style="background-color: rgb(79, 70, 229); color: #ffffff; order: -1;"
    onmouseover="this.style.backgroundColor='rgb(67, 56, 202)';"
    onmouseout="this.style.backgroundColor='rgb(79, 70, 229)';"
    title="{{ $docsTitle }}"
>
    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
    </svg>
    <span>Ayuda</span>
</a>
