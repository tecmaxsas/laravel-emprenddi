<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditRegistry;
use App\Services\Restaurant\BrowserPrintQueue;
use App\Support\CurrentCompany;
use App\Support\ModuleGate;
use App\Support\SedeDelCajero;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
        // Cola de impresión browser (QZ Tray) — vive durante el request.
        $this->app->singleton(BrowserPrintQueue::class);
        // Acumula entradas de auditoría durante la transacción en curso.
        $this->app->singleton(AuditRecorder::class);
        // Empresas ya consultadas por ModuleGate en este request / job.
        $this->app->scoped(ModuleGate::MEMO, fn () => new \ArrayObject);
        // Permisos del usuario ya resueltos en este request / job.
        $this->app->scoped(\App\Support\PermissionMemo::BINDING, fn () => new \ArrayObject);
    }

    public function boot(): void
    {
        // Un cambio de modulos tiene que verse en el mismo request.
        Company::saved(fn () => ModuleGate::flush());

        // Sin la opcion «Todos» en la paginacion. Filament la guarda en la
        // sesion y la reaplica cada vez que el usuario vuelve a la lista: el
        // 2026-11-17 un usuario de IMPERIO la tenia en productos (6.894) y en
        // facturas de venta, y cada visita pintaba todo, pasaba de 90 s (504)
        // y ocupaba un worker de los 5. Un valor guardado que ya no esta en
        // las opciones Filament lo descarta solo y vuelve a la primera.
        // Las tablas que definen sus propias opciones conservan las suyas.
        \Filament\Tables\Table::configureUsing(
            fn (\Filament\Tables\Table $table) => $table->paginationPageOptions([10, 25, 50, 100]),
        );

        // Spatie borra su cache al cambiar roles o permisos: el memo de
        // permisos tambien, para que el cambio se vea en el mismo request.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Cache\Events\KeyForgotten::class,
            function ($event) {
                if ($event->key === config('permission.cache.key')) {
                    \App\Support\PermissionMemo::flush();
                }
            },
        );

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registrarAuditoria();

        // Cada cajero abre caja en su sede (users.pos_location_id).
        SedeDelCajero::registrar();
    }

    /**
     * Engancha la bitácora: los modelos del registro y los eventos de sesión.
     *
     * Va aquí y no en un trait dentro de cada modelo para que la lista de lo
     * que se audita se lea en un solo lugar (AuditRegistry) en vez de repartida
     * en treinta archivos.
     */
    private function registrarAuditoria(): void
    {
        foreach (array_keys(AuditRegistry::MODELOS) as $modelo) {
            $modelo::observe(AuditObserver::class);
        }

        // Si la operación se revierte, no ocurrió: lo encolado por encima del
        // nivel al que volvió la transacción se descarta. Sin esto, esas
        // entradas quedaban en memoria y se colaban en el siguiente commit.
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $evento) {
            app(AuditRecorder::class)->descartarDesde($evento->connection->transactionLevel());
        });

        Event::listen(Login::class, function (Login $evento) {
            app(AuditRecorder::class)->sesion(
                AuditLog::EVENT_LOGIN,
                $evento->user instanceof User ? $evento->user : null,
            );
        });

        Event::listen(Logout::class, function (Logout $evento) {
            app(AuditRecorder::class)->sesion(
                AuditLog::EVENT_LOGOUT,
                $evento->user instanceof User ? $evento->user : null,
            );
        });

        // Un intento fallido no trae usuario autenticado, pero sí las
        // credenciales: se guarda el correo tecleado —nunca la contraseña— para
        // poder distinguir un dedo torpe de alguien probando claves.
        Event::listen(Failed::class, function (Failed $evento) {
            $correo = $evento->credentials['email'] ?? $evento->credentials['username'] ?? null;

            app(AuditRecorder::class)->sesion(
                AuditLog::EVENT_LOGIN_FAILED,
                $evento->user instanceof User ? $evento->user : null,
                companyId: $evento->user?->company_id
                    ?? User::withoutGlobalScopes()->where('email', $correo)->value('company_id'),
                datos: ['intento' => $correo],
            );
        });
    }
}
