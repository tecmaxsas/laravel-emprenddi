<x-filament-panels::page.simple>
    <x-auth-shell>
        <div class="auth-head">
            <h1 class="auth-h1">Recuperá tu contraseña</h1>
            <p class="auth-sub">Te enviaremos un enlace para restablecerla a tu correo</p>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

        <x-filament-panels::form id="form" wire:submit="request">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}

        @if (filament()->hasLogin())
            <p class="auth-alt">
                ¿Recordaste tu contraseña? {{ $this->loginAction }}
            </p>
        @endif
    </x-auth-shell>
</x-filament-panels::page.simple>
