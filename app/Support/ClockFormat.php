<?php

namespace App\Support;

/**
 * Cómo se escriben las horas en pantalla.
 *
 * El módulo de parqueadero mostraba «18:15» y el operario tiene que traducirlo
 * mentalmente cada vez. En Colombia la hora se lee en formato de 12 horas: un
 * cajero que ve «6:15 pm» no se equivoca, con «18:15» a veces sí —y en un
 * parqueadero la hora de entrada es lo que decide cuánto se cobra—.
 *
 * Los formatos viven aquí y no sueltos por las vistas porque son una decisión
 * de producto, no un detalle de cada pantalla: si mañana se prefiere «6:15 p. m.»
 * se cambia en un sitio y no en dieciocho.
 */
class ClockFormat
{
    /** Hora sola, compacta. «6:15 pm» — para fichas y etiquetas estrechas. */
    public const TIME = 'g:i a';

    /** Hora sola, con el cero delante. «06:15 pm» — para tablas alineadas. */
    public const TIME_PADDED = 'h:i a';

    /** Fecha y hora. «15/09/2026 06:15 pm» */
    public const DATETIME = 'd/m/Y h:i a';

    /**
     * Fecha y hora con segundos. «15/09/2026 06:15:42 pm»
     *
     * Los segundos importan donde el minuto decide plata: la entrada y la
     * salida de un vehículo, que es lo que se factura.
     */
    public const DATETIME_SECONDS = 'd/m/Y h:i:s a';
}
