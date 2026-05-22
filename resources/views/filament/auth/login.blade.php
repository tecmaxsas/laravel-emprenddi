<x-filament-panels::page.simple>
    <x-auth-shell>
        <div class="auth-head">
            <h1 class="auth-h1">Iniciá sesión</h1>
            <p class="auth-sub">Ingresá a tu cuenta para continuar</p>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

        <x-filament-panels::form id="form" wire:submit="authenticate">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}

        @if (filament()->hasRegistration())
            <p class="auth-alt">
                ¿No tenés cuenta? {{ $this->registerAction }}
            </p>
        @endif

        <p class="auth-policy">
            Al continuar aceptás nuestros
            <a href="{{ url('/legal/terminos') }}" target="_blank" rel="noopener">Términos y condiciones</a>
            y la
            <a href="{{ url('/legal/privacidad') }}" target="_blank" rel="noopener">Política de privacidad</a>.
        </p>
    </x-auth-shell>
</x-filament-panels::page.simple>
