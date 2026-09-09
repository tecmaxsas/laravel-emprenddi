<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Exceptions\LivewireReleaseTokenMismatchException;
use Livewire\Exceptions\PublicPropertyNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Una pantalla vieja no puede terminar en una traza de error.
         *
         * El POS y el terminal de parqueadero se dejan abiertos toda la
         * jornada. Cuando alguien vuelve despues de horas, la sesion ya vencio
         * o la version desplegada cambio, y la peticion de Livewire llega con
         * un estado que el servidor ya no reconoce. Eso no es un fallo del
         * programa: es una pagina caducada.
         *
         * Livewire ya sabe manejar un 419 —muestra «esta pagina expiro,
         * ¿recargar?» y recarga—, asi que estas excepciones se traducen a eso
         * en vez de mostrarle a un cajero un volcado de PHP.
         *
         * Pero se registran SIEMPRE antes de convertirlas. Estas excepciones
         * tambien aparecen cuando hay un error de programacion de verdad, y
         * taparlas sin dejar rastro cambiaria un error visible por uno
         * invisible. El log queda con el componente y la propiedad, que es
         * justo lo que hace falta para investigarlo.
         */
        $paginaCaducada = [
            PublicPropertyNotFoundException::class,
            ComponentNotFoundException::class,
            LivewireReleaseTokenMismatchException::class,
            TokenMismatchException::class,
        ];

        foreach ($paginaCaducada as $clase) {
            $exceptions->render(function (Throwable $e, Request $request) use ($clase) {
                if (! $e instanceof $clase || ! $request->hasHeader('X-Livewire')) {
                    return null;
                }

                Log::warning('[Livewire] Peticion con estado caducado: '.$e->getMessage(), [
                    'url' => $request->fullUrl(),
                    'usuario' => $request->user()?->id,
                    'componentes' => collect($request->input('components', []))
                        ->map(fn ($c) => data_get($c, 'snapshot.memo.name'))
                        ->filter()
                        ->values()
                        ->all(),
                    'actualizaciones' => collect($request->input('components', []))
                        ->flatMap(fn ($c) => array_keys((array) data_get($c, 'updates', [])))
                        ->values()
                        ->all(),
                ]);

                abort(419);
            });
        }
    })->create();
