{{--
    La flecha de los <select> en las pantallas operativas.

    EL PROBLEMA. Filament —vía el plugin de formularios de Tailwind— ya le
    dibuja una flecha a cada `select`, y el navegador dibuja la suya encima.
    Quedaban dos flechas sobre el texto, tapando lo que el usuario había
    elegido: en el modal de cobro del POS no se leía el método de pago.

    POR QUE ESTA EN UN PARCIAL Y NO EN CADA PANTALLA. El mismo defecto estaba
    en ocho blades —POS de retail, restaurante, parqueadero, recepción del
    hotel, toma de pedidos, despacho, cocina y calendario—. Copiar el bloque
    ocho veces es la forma conocida de que dentro de un mes haya ocho flechas
    distintas, y de que la novena pantalla nazca otra vez con dos.

    `padding-right` es lo que de verdad resuelve la queja: reserva el sitio de
    la flecha para que el texto no quede debajo.

    Lleva `!important` a propósito: compite con el CSS del propio Filament, que
    se carga después. Es el mismo criterio que ya usan estas pantallas para
    esconder la barra lateral.
--}}
<style>
    .fi-page select {
        appearance: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 10px center !important;
        background-size: 16px 16px !important;
        padding-right: 34px !important;
        /* Un nombre largo se corta con puntos suspensivos en vez de
           desbordarse sobre la flecha. */
        text-overflow: ellipsis;
    }

    :is(.dark) .fi-page select {
        background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%239ca3af' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
    }
</style>
