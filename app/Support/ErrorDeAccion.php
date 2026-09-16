<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisa del error en pantalla **y lo deja escrito**.
 *
 * Las acciones de Filament envuelven su trabajo en un try/catch para que un
 * fallo no tumbe la página. El problema es lo que hacían después: mostrar el
 * mensaje y tirar la excepción a la basura.
 *
 * Eso se pagó caro con un «Array to string conversion» al enviar una nota
 * crédito a la DIAN. El usuario veía seis palabras sin contexto, el log del
 * servidor estaba vacío —no porque no hubiera error, sino porque nadie lo
 * escribía— y hubo que salir a adivinar dónde estaba, con dos despliegues de
 * por medio que no arreglaron nada.
 *
 * Un error que solo existe en un toast que el usuario ya cerró no se puede
 * diagnosticar. Por eso todo lo que se le muestre a alguien queda también en el
 * log, con su traza, su usuario y el documento sobre el que pasó.
 */
class ErrorDeAccion
{
    /**
     * @param  string  $accion  Qué se estaba intentando, para buscarlo después.
     * @param  array<string, mixed>  $contexto  Datos que ayuden a ubicar el caso.
     */
    public static function reportar(
        Throwable $e,
        string $accion,
        array $contexto = [],
        string $titulo = 'Error',
    ): void {
        Log::error("Falló {$accion}", [
            'accion' => $accion,
            'excepcion' => $e::class,
            'mensaje' => $e->getMessage(),
            'archivo' => $e->getFile().':'.$e->getLine(),
            'usuario_id' => Auth::id(),
            'empresa_id' => Auth::user()?->company_id,
            ...$contexto,
            // La traza es el único dato que de verdad ubica un error de este
            // tipo. Se recorta porque el resto son marcos del framework que no
            // dicen nada y llenan el archivo.
            'traza' => collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
        ]);

        Notification::make()
            ->danger()
            ->title($titulo)
            ->body($e->getMessage())
            ->persistent()
            ->send();
    }
}
