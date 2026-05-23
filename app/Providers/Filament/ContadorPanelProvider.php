<?php

namespace App\Providers\Filament;

use App\Filament\Contador\Pages\Auth\AccountantRegister;
use App\Http\Middleware\RequireAccountantActiveCompany;
use App\Http\Middleware\SetAccountantActiveCompany;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ContadorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('contador')
            ->path('contador')
            ->login(\App\Filament\Auth\Login::class)
            ->passwordReset(\App\Filament\Auth\RequestPasswordReset::class, \App\Filament\Auth\ResetPassword::class)
            ->registration(AccountantRegister::class)
            ->brandName('Portal Contador')
            ->brandLogo(asset('logos/logo_emprenddi.svg'))
            ->darkModeBrandLogo(asset('logos/logo_emprenddi_blanco_fondo_oscuro.svg'))
            ->brandLogoHeight('4.5rem')
            ->favicon(asset('logos/favicon_emprenddi.svg'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->sidebarCollapsibleOnDesktop()
            // Resources del panel App — los reusamos. ChecksPermission +
            // el rol accountant_external filtran lo que el contador puede
            // ver y hacer.
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            // Páginas propias del Contador (selector de empresa, etc.).
            ->discoverPages(in: app_path('Filament/Contador/Pages'), for: 'App\\Filament\\Contador\\Pages')
            // También las páginas de reportes del App panel.
            ->discoverPages(in: app_path('Filament/App/Pages/Reports'), for: 'App\\Filament\\App\\Pages\\Reports')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetAccountantActiveCompany::class,
                RequireAccountantActiveCompany::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.contador.topbar.active-company')->render(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('partials.filament-brand-styles')->render(),
            );
    }
}
