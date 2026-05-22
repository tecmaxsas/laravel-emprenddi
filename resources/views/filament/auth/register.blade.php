<x-filament-panels::page.simple>
    <x-auth-shell>
        <div class="auth-head">
            <h1 class="auth-h1">{{ $this->getHeading() }}</h1>
            @php $authSub = $this->getSubheading(); @endphp
            @if ($authSub)
                <p class="auth-sub">{{ $authSub }}</p>
            @endif
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

        <x-filament-panels::form id="form" wire:submit="register">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_REGISTER_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}

        @if (filament()->hasLogin())
            <p class="auth-alt">
                ¿Ya tenés cuenta? {{ $this->loginAction }}
            </p>
        @endif

        <p class="auth-policy">
            Al registrarte aceptás nuestros
            <a href="{{ url('/legal/terminos') }}" target="_blank" rel="noopener">Términos y condiciones</a>
            y la
            <a href="{{ url('/legal/privacidad') }}" target="_blank" rel="noopener">Política de privacidad</a>.
        </p>
    </x-auth-shell>
</x-filament-panels::page.simple>
