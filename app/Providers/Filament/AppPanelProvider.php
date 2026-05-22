<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Auth\Register;
use App\Http\Middleware\SetActiveCompany;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Filament\View\PanelsRenderHook;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login(\App\Filament\Auth\Login::class)
            ->passwordReset(\App\Filament\Auth\RequestPasswordReset::class, \App\Filament\Auth\ResetPassword::class)
            ->registration(Register::class)
            ->brandName('Emprenddi')
            ->colors([
                'primary' => Color::Indigo,
            ])
            // Sidebar contraíble en desktop: el hamburger queda en el topbar y
            // al contraer la barra lateral muestra solo iconos.
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                \App\Filament\App\Widgets\DashboardOverviewWidget::class,
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
                SetActiveCompany::class,
            ])
            // Botón "POS" en el topbar — order:-1 lo coloca a la izquierda
            // del user menu en el flex container del TOPBAR_END.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.app.topbar.pos-button')->render(),
            )
            // Puente QZ Tray — impresión ESC/POS en impresoras locales del cajero.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.qz-bridge')->render(),
            )
            // Reemplaza el chevron del botón de colapso del sidebar por
            // un icono hamburguesa. El bundle de Filament no expone forma
            // declarativa de cambiar ese icono — usamos CSS con mask para
            // ocultar el SVG original y dibujar el burger.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => <<<'HTML'
<style>
    /* Botón de colapso del sidebar en desktop: oculta el chevron y pinta
       una hamburguesa via CSS mask. El selector cubre varias clases
       posibles que Filament usa según versión. */
    .fi-sidebar-header .fi-icon-btn > svg,
    [aria-label*="Collapse sidebar" i] > svg,
    [aria-label*="Expand sidebar" i] > svg {
        display: none !important;
    }
    .fi-sidebar-header .fi-icon-btn::before,
    [aria-label*="Collapse sidebar" i]::before,
    [aria-label*="Expand sidebar" i]::before {
        content: "";
        display: inline-block;
        width: 20px;
        height: 20px;
        background-color: currentColor;
        -webkit-mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='2' stroke='currentColor'><path stroke-linecap='round' stroke-linejoin='round' d='M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5'/></svg>") no-repeat center / contain;
                mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='2' stroke='currentColor'><path stroke-linecap='round' stroke-linejoin='round' d='M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5'/></svg>") no-repeat center / contain;
    }
</style>
HTML,
            );
    }
}
