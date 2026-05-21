<?php

namespace App\Providers;

use App\Support\CurrentCompany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
        // Cola de impresión browser (QZ Tray) — vive durante el request.
        $this->app->singleton(\App\Services\Restaurant\BrowserPrintQueue::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
