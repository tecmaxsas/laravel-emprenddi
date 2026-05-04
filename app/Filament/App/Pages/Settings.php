<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Configuraciones de la empresa. Página única con tabs:
 *  - Empresa: edita los campos de companies (NIT, razón social, contacto, etc).
 *  - POS: toggles que controlan el comportamiento del terminal POS,
 *    persistidos en companies.settings.pos.*
 *
 * Cada tab requiere su propio permiso (company.settings, pos.settings).
 * Si el usuario solo tiene uno, el otro tab se oculta — la página sigue
 * accesible mientras tenga al menos uno.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuraciones';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $title = 'Configuraciones';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'settings';

    protected static string $view = 'filament.app.pages.settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAny(['company.settings', 'pos.settings']);
    }

    public function mount(): void
    {
        $company = $this->getCompany();
        $settings = $company->settings ?? [];

        $this->data = [
            // Empresa
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'nit' => $company->nit,
            'dv' => $company->dv,
            'address' => $company->address,
            'city' => $company->city,
            'department' => $company->department,
            'country' => $company->country,
            'phone' => $company->phone,
            'email' => $company->email,
            'website' => $company->website,
            'currency' => $company->currency,
            'timezone' => $company->timezone,

            // POS settings (settings.pos.*)
            'pos_allow_price_modification' => (bool) data_get($settings, 'pos.allow_price_modification', true),
            'pos_allow_discount' => (bool) data_get($settings, 'pos.allow_discount', true),
            'pos_require_customer' => (bool) data_get($settings, 'pos.require_customer', false),
            'pos_print_after_sale' => (bool) data_get($settings, 'pos.print_after_sale', true),
            'pos_blind_cash_close' => (bool) data_get($settings, 'pos.blind_cash_close', false),
            'pos_allow_negative_stock' => (bool) data_get($settings, 'pos.allow_negative_stock', false),
            'pos_default_tip_percent' => (float) data_get($settings, 'pos.default_tip_percent', 0),
        ];

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('settings_tabs')
                    ->columnSpanFull()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Empresa')
                            ->icon('heroicon-o-building-office-2')
                            ->visible(fn () => auth()->user()?->can('company.settings'))
                            ->schema($this->companyTabSchema()),

                        Forms\Components\Tabs\Tab::make('POS')
                            ->icon('heroicon-o-computer-desktop')
                            ->visible(fn () => auth()->user()?->can('pos.settings'))
                            ->schema($this->posTabSchema()),
                    ]),
            ])
            ->statePath('data');
    }

    protected function companyTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre comercial')
                        ->required()
                        ->maxLength(150),

                    Forms\Components\TextInput::make('legal_name')
                        ->label('Razón social')
                        ->maxLength(200)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('nit')
                        ->label('NIT (sin DV)')
                        ->required()
                        ->maxLength(30),

                    Forms\Components\TextInput::make('dv')
                        ->label('DV')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(9)
                        ->maxLength(1),

                    Forms\Components\Placeholder::make('full_nit_display')
                        ->label('NIT completo')
                        ->content(fn (Forms\Get $get) => trim(($get('nit') ?? '').($get('dv') !== null && $get('dv') !== '' ? '-'.$get('dv') : ''))),
                ]),

            Forms\Components\Section::make('Contacto y dirección')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('address')->label('Dirección')->columnSpan(3),
                    Forms\Components\TextInput::make('city')->label('Ciudad'),
                    Forms\Components\TextInput::make('department')->label('Departamento'),
                    Forms\Components\TextInput::make('country')->label('País')->default('CO')->maxLength(2),
                    Forms\Components\TextInput::make('phone')->label('Teléfono')->tel(),
                    Forms\Components\TextInput::make('email')->label('Email')->email(),
                    Forms\Components\TextInput::make('website')->label('Sitio web')->url(),
                ]),

            Forms\Components\Section::make('Localización y moneda')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD', 'EUR' => 'EUR'])
                        ->default('COP')
                        ->required(),

                    Forms\Components\TextInput::make('timezone')
                        ->label('Zona horaria')
                        ->default('America/Bogota')
                        ->required(),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveCompany')
                    ->label('Guardar datos de empresa')
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->action('saveCompany'),
            ])->alignEnd(),
        ];
    }

    protected function posTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Permisos del cajero en el POS')
                ->description('Controla qué acciones puede hacer el cajero al vender. Útil para tiendas con políticas estrictas de precio o cliente.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('pos_allow_price_modification')
                        ->label('Permitir modificar precio en el carrito')
                        ->helperText('Si está desactivado, el precio del producto queda bloqueado al precio de catálogo.'),

                    Forms\Components\Toggle::make('pos_allow_discount')
                        ->label('Permitir descuento por línea')
                        ->helperText('Cajero puede aplicar % de descuento a cada producto.'),

                    Forms\Components\Toggle::make('pos_require_customer')
                        ->label('Cliente obligatorio')
                        ->helperText('Bloquea procesar venta con "Consumidor Final" — exige seleccionar cliente real.'),

                    Forms\Components\Toggle::make('pos_allow_negative_stock')
                        ->label('Permitir vender sin stock')
                        ->helperText('Si está desactivado, el sistema rechaza ventas que dejarían inventario negativo.'),
                ]),

            Forms\Components\Section::make('Comportamiento de venta')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('pos_print_after_sale')
                        ->label('Imprimir ticket automáticamente al cerrar venta')
                        ->helperText('Si está desactivado, el cajero debe abrir manualmente el ticket desde la factura.'),

                    Forms\Components\TextInput::make('pos_default_tip_percent')
                        ->label('Propina sugerida (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(50)
                        ->step(0.5)
                        ->default(0)
                        ->helperText('0 = sin propina sugerida. Aplica a establecimientos como restaurantes.'),
                ]),

            Forms\Components\Section::make('Sesiones de caja')
                ->description('Reglas para apertura y cierre de turno del cajero (próximamente — Iter 5).')
                ->schema([
                    Forms\Components\Toggle::make('pos_blind_cash_close')
                        ->label('Cierre de caja oculto')
                        ->helperText('El cajero no ve el monto esperado al hacer cuenta de cierre — solo digita lo que físicamente cuenta. Detecta diferencias sin sesgar al cajero.'),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('savePos')
                    ->label('Guardar configuración POS')
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->action('savePos'),
            ])->alignEnd(),
        ];
    }

    public function saveCompany(): void
    {
        if (! auth()->user()->can('company.settings')) {
            $this->errorNotif('Sin permiso para editar la empresa');
            return;
        }

        $state = $this->form->getState();
        $company = $this->getCompany();

        $company->update([
            'name' => $state['name'],
            'legal_name' => $state['legal_name'] ?: null,
            'nit' => $state['nit'],
            'dv' => $state['dv'] !== null && $state['dv'] !== '' ? (int) $state['dv'] : null,
            'address' => $state['address'] ?: null,
            'city' => $state['city'] ?: null,
            'department' => $state['department'] ?: null,
            'country' => $state['country'] ?: 'CO',
            'phone' => $state['phone'] ?: null,
            'email' => $state['email'] ?: null,
            'website' => $state['website'] ?: null,
            'currency' => $state['currency'] ?: 'COP',
            'timezone' => $state['timezone'] ?: 'America/Bogota',
        ]);

        Notification::make()->title('Datos de empresa actualizados')->success()->send();
    }

    public function savePos(): void
    {
        if (! auth()->user()->can('pos.settings')) {
            $this->errorNotif('Sin permiso para editar configuración POS');
            return;
        }

        $state = $this->form->getState();
        $company = $this->getCompany();
        $settings = $company->settings ?? [];

        // Mergeo en settings.pos.* sin perder otras keys.
        $settings['pos'] = array_merge($settings['pos'] ?? [], [
            'allow_price_modification' => (bool) ($state['pos_allow_price_modification'] ?? true),
            'allow_discount' => (bool) ($state['pos_allow_discount'] ?? true),
            'require_customer' => (bool) ($state['pos_require_customer'] ?? false),
            'print_after_sale' => (bool) ($state['pos_print_after_sale'] ?? true),
            'blind_cash_close' => (bool) ($state['pos_blind_cash_close'] ?? false),
            'allow_negative_stock' => (bool) ($state['pos_allow_negative_stock'] ?? false),
            'default_tip_percent' => (float) ($state['pos_default_tip_percent'] ?? 0),
        ]);

        $company->update(['settings' => $settings]);

        Notification::make()->title('Configuración POS guardada')->success()->send();
    }

    protected function getCompany(): Company
    {
        $companyId = Auth::user()->company_id;
        if (! $companyId) {
            abort(403, 'Usuario sin empresa asociada.');
        }
        return Company::findOrFail($companyId);
    }

    protected function errorNotif(string $title): void
    {
        Notification::make()->title($title)->danger()->send();
    }
}
