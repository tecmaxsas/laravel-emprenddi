<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditRegistry;
use App\Services\Restaurant\BrowserPrintQueue;
use App\Support\CurrentCompany;
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
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registrarAuditoria();
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
