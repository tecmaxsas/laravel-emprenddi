<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Support\ModuleGate;
use App\Support\AppointmentsSettings;
use App\Support\CommissionsSettings;
use App\Support\GiftCardsSettings;
use App\Support\PromotionsSettings;
use App\Support\RestaurantSettings;
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
            'logo_path' => $company->logo_path,
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

            // Inventario / seriales (settings.serials.*)
            'serials_enabled' => (bool) data_get($settings, 'serials.enabled', false),

            // Garantías (settings.warranties.*)
            'warranties_enabled' => (bool) data_get($settings, 'warranties.enabled', false),

            // Etiquetas (settings.labels.*)
            'labels_enabled' => (bool) data_get($settings, 'labels.enabled', false),
            'labels_fields' => (array) data_get($settings, 'labels.fields', \App\Support\LabelsSettings::DEFAULT_FIELDS),
            'labels_barcode_type' => (string) data_get($settings, 'labels.barcode_type', 'CODE128'),
            'labels_columns_per_sheet' => (int) data_get($settings, 'labels.columns_per_sheet', 3),
            'labels_width_mm' => (int) data_get($settings, 'labels.width_mm', 50),
            'labels_height_mm' => (int) data_get($settings, 'labels.height_mm', 30),
            'labels_show_currency_symbol' => (bool) data_get($settings, 'labels.show_currency_symbol', true),

            // POS settings (settings.pos.*)
            'pos_default_invoice_kind' => (string) data_get($settings, 'pos.default_invoice_kind', 'pos'),
            'pos_allow_price_modification' => (bool) data_get($settings, 'pos.allow_price_modification', true),
            'pos_allow_discount' => (bool) data_get($settings, 'pos.allow_discount', true),
            'pos_require_customer' => (bool) data_get($settings, 'pos.require_customer', false),
            'pos_print_after_sale' => (bool) data_get($settings, 'pos.print_after_sale', true),
            'pos_blind_cash_close' => (bool) data_get($settings, 'pos.blind_cash_close', false),
            'pos_allow_negative_stock' => (bool) data_get($settings, 'pos.allow_negative_stock', false),
            'pos_default_tip_percent' => (float) data_get($settings, 'pos.default_tip_percent', 0),
            'pos_discount_supervisor_threshold' => (float) data_get($settings, 'pos.discount_supervisor_threshold', 10),
            'pos_allow_tax_modification' => (bool) data_get($settings, 'pos.allow_tax_modification', true),
        ];

        // Restaurant settings (settings.restaurant.enable_*) — uno por feature
        foreach (array_keys(RestaurantSettings::FEATURES) as $key) {
            $default = RestaurantSettings::FEATURES[$key]['default'];
            $this->data['restaurant_enable_'.$key] = (bool) data_get(
                $settings,
                "restaurant.enable_{$key}",
                $default,
            );
        }

        // Cuenta de propinas por pagar
        $this->data['restaurant_tip_payable_account_id'] = data_get($settings, 'restaurant.tip_payable_account_id');

        // Promotions settings (settings.promotions.*) — uno por feature
        foreach (array_keys(PromotionsSettings::FEATURES) as $key) {
            $default = PromotionsSettings::FEATURES[$key]['default'];
            $this->data['promotions_'.$key] = (bool) data_get(
                $settings,
                "promotions.{$key}",
                $default,
            );
        }

        // Gift cards settings (settings.gift_cards.*)
        foreach (array_keys(GiftCardsSettings::FEATURES) as $key) {
            $default = GiftCardsSettings::FEATURES[$key]['default'];
            $this->data['gift_cards_'.$key] = data_get(
                $settings,
                "gift_cards.{$key}",
                $default,
            );
        }

        // Commissions settings (settings.commissions.*)
        foreach (array_keys(CommissionsSettings::FEATURES) as $key) {
            $default = CommissionsSettings::FEATURES[$key]['default'];
            $this->data['commissions_'.$key] = data_get(
                $settings,
                "commissions.{$key}",
                $default,
            );
        }

        // Appointments settings (settings.appointments.*)
        foreach (array_keys(AppointmentsSettings::FEATURES) as $key) {
            $default = AppointmentsSettings::FEATURES[$key]['default'];
            $this->data['appointments_'.$key] = data_get(
                $settings,
                "appointments.{$key}",
                $default,
            );
        }

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

                        Forms\Components\Tabs\Tab::make('Restaurante')
                            ->icon('heroicon-o-building-storefront')
                            ->visible(fn () => ModuleGate::active('restaurant')
                                && auth()->user()?->can('company.settings'))
                            ->schema($this->restaurantTabSchema()),

                        Forms\Components\Tabs\Tab::make('Promociones')
                            ->icon('heroicon-o-tag')
                            ->visible(fn () => auth()->user()?->can('company.settings'))
                            ->schema($this->promotionsTabSchema()),

                        Forms\Components\Tabs\Tab::make('Gift Cards')
                            ->icon('heroicon-o-gift')
                            ->visible(fn () => auth()->user()?->can('company.settings'))
                            ->schema($this->giftCardsTabSchema()),

                        Forms\Components\Tabs\Tab::make('Comisiones')
                            ->icon('heroicon-o-currency-dollar')
                            ->visible(fn () => auth()->user()?->can('company.settings'))
                            ->schema($this->commissionsTabSchema()),

                        Forms\Components\Tabs\Tab::make('Citas')
                            ->icon('heroicon-o-calendar-days')
                            ->visible(fn () => auth()->user()?->can('company.settings'))
                            ->schema($this->appointmentsTabSchema()),
                    ]),
            ])
            ->statePath('data');
    }

    protected function companyTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Identidad visual')
                ->description('El logo aparece en los tickets de venta, facturas impresas, menú público y otros documentos.')
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')
                        ->label('Logo de la empresa')
                        ->image()
                        ->imageEditor()
                        ->directory('companies/logos')
                        ->visibility('public')
                        ->maxSize(2048)
                        ->helperText('PNG o JPG, máximo 2 MB. Se recomienda fondo transparente y proporción horizontal (ej. 600×200 px).'),
                ]),

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

            Forms\Components\Section::make('Inventario por seriales')
                ->description('Para tiendas que venden equipos con número de serie (computadores, impresoras, cajones monederos, etc.). Cuando esté activo, cada producto puede marcarse como "maneja seriales": entrarán al inventario por número de serie en las compras y se podrán pistolear en el POS para vincular cada unidad vendida con su garantía.')
                ->schema([
                    Forms\Components\Toggle::make('serials_enabled')
                        ->label('Activar gestión de seriales')
                        ->helperText('Si lo desactivas no se borran los seriales existentes, solo se oculta la UI.'),
                ]),

            Forms\Components\Section::make('Garantías')
                ->description('Gestiona reclamaciones de garantía / RMA: cuando un cliente trae un equipo, se crea un ticket vinculado a su factura o serial, se asigna a un técnico y se hace seguimiento de estado (recibida → en reparación → resuelta / reemplazada / rechazada → entregada). El plazo se calcula por producto (campo "Días de garantía" en cada SKU).')
                ->schema([
                    Forms\Components\Toggle::make('warranties_enabled')
                        ->label('Activar módulo de garantías')
                        ->helperText('Si lo desactivas no se borran los tickets existentes, solo se oculta el menú.'),
                ]),

            Forms\Components\Section::make('Etiquetas con código de barras')
                ->description('Al activarlo, aparecen botones "Imprimir etiquetas" en el listado de productos y al postear compras o ajustes de inventario. Elige qué información va en la etiqueta y sus dimensiones.')
                ->schema([
                    Forms\Components\Toggle::make('labels_enabled')
                        ->label('Activar impresión de etiquetas')
                        ->live()
                        ->helperText('Cuando está activo, verás la opción de imprimir etiquetas en varios puntos de la app.'),

                    Forms\Components\Group::make()
                        ->visible(fn (Forms\Get $get) => (bool) $get('labels_enabled'))
                        ->columns(2)
                        ->schema([
                            Forms\Components\CheckboxList::make('labels_fields')
                                ->label('Campos que aparecen en la etiqueta')
                                ->options(\App\Support\LabelsSettings::AVAILABLE_FIELDS)
                                ->default(\App\Support\LabelsSettings::DEFAULT_FIELDS)
                                ->columns(2)
                                ->columnSpanFull(),

                            Forms\Components\Select::make('labels_barcode_type')
                                ->label('Tipo de código de barras')
                                ->options(\App\Support\LabelsSettings::BARCODE_TYPES)
                                ->default('CODE128')
                                ->native(false)
                                ->helperText('CODE128 acepta cualquier texto (código o barcode). EAN-13 exige 12 dígitos.'),

                            Forms\Components\TextInput::make('labels_columns_per_sheet')
                                ->label('Etiquetas por fila')
                                ->numeric()->minValue(1)->maxValue(10)->default(3),

                            Forms\Components\TextInput::make('labels_width_mm')
                                ->label('Ancho (mm)')
                                ->numeric()->minValue(20)->maxValue(200)->default(50)
                                ->helperText('Tamaños comunes: 50, 40, 60, 80.'),

                            Forms\Components\TextInput::make('labels_height_mm')
                                ->label('Alto (mm)')
                                ->numeric()->minValue(10)->maxValue(150)->default(30)
                                ->helperText('Tamaños comunes: 30, 20, 40, 50.'),

                            Forms\Components\Toggle::make('labels_show_currency_symbol')
                                ->label('Mostrar símbolo $ en el precio')
                                ->default(true)
                                ->columnSpanFull(),

                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('previewLabels')
                                    ->label('Ver preview con la configuración actual')
                                    ->icon('heroicon-o-eye')
                                    ->color('info')
                                    ->url(fn () => route('labels.print', ['preview' => 1]), shouldOpenInNewTab: true),
                            ])->columnSpanFull(),
                        ]),
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

                    Forms\Components\Toggle::make('pos_allow_tax_modification')
                        ->label('Permitir modificar / agregar impuesto por línea')
                        ->helperText('Cajero puede cambiar el impuesto que trae el producto, o asignarle uno si no tenía. Útil para productos con IVA variable según el cliente.'),

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
                    Forms\Components\Select::make('pos_default_invoice_kind')
                        ->label('Tipo de factura por defecto')
                        ->options([
                            'pos' => 'POS (no electrónica)',
                            'electronic' => 'Electrónica (DIAN)',
                        ])
                        ->default('pos')
                        ->native(false)
                        ->required()
                        ->helperText('Define qué tipo de factura emite el POS al abrir cada venta. Las empresas que solo facturan electrónicamente pueden elegir "Electrónica" para evitar cambiar el tipo en cada venta. El cajero puede cambiarlo manualmente en el momento de cobrar.'),

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

                    Forms\Components\TextInput::make('pos_discount_supervisor_threshold')
                        ->label('Umbral % para pedir aprobación de supervisor')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.5)
                        ->default(10)
                        ->helperText('Si el cajero aplica un descuento mayor a este %, el POS pide la contraseña de un usuario con permiso "Aprobar descuentos". Usa 0 para no pedir nunca aprobación.'),
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

        $settings = $company->settings ?? [];
        $settings['serials'] = array_merge($settings['serials'] ?? [], [
            'enabled' => (bool) ($state['serials_enabled'] ?? false),
        ]);
        $settings['warranties'] = array_merge($settings['warranties'] ?? [], [
            'enabled' => (bool) ($state['warranties_enabled'] ?? false),
        ]);
        $settings['labels'] = array_merge($settings['labels'] ?? [], [
            'enabled' => (bool) ($state['labels_enabled'] ?? false),
            'fields' => array_values((array) ($state['labels_fields'] ?? \App\Support\LabelsSettings::DEFAULT_FIELDS)),
            'barcode_type' => (string) ($state['labels_barcode_type'] ?? 'CODE128'),
            'columns_per_sheet' => max(1, min(10, (int) ($state['labels_columns_per_sheet'] ?? 3))),
            'width_mm' => max(20, min(200, (int) ($state['labels_width_mm'] ?? 50))),
            'height_mm' => max(10, min(150, (int) ($state['labels_height_mm'] ?? 30))),
            'show_currency_symbol' => (bool) ($state['labels_show_currency_symbol'] ?? true),
        ]);

        $company->update([
            'logo_path' => $state['logo_path'] ?: null,
            'settings' => $settings,
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
        $defaultInvoiceKind = in_array($state['pos_default_invoice_kind'] ?? 'pos', ['pos', 'electronic'], true)
            ? $state['pos_default_invoice_kind']
            : 'pos';

        $settings['pos'] = array_merge($settings['pos'] ?? [], [
            'default_invoice_kind' => $defaultInvoiceKind,
            'allow_price_modification' => (bool) ($state['pos_allow_price_modification'] ?? true),
            'allow_discount' => (bool) ($state['pos_allow_discount'] ?? true),
            'require_customer' => (bool) ($state['pos_require_customer'] ?? false),
            'print_after_sale' => (bool) ($state['pos_print_after_sale'] ?? true),
            'blind_cash_close' => (bool) ($state['pos_blind_cash_close'] ?? false),
            'allow_negative_stock' => (bool) ($state['pos_allow_negative_stock'] ?? false),
            'default_tip_percent' => (float) ($state['pos_default_tip_percent'] ?? 0),
            'discount_supervisor_threshold' => (float) ($state['pos_discount_supervisor_threshold'] ?? 10),
            'allow_tax_modification' => (bool) ($state['pos_allow_tax_modification'] ?? true),
        ]);

        $company->update(['settings' => $settings]);

        Notification::make()->title('Configuración POS guardada')->success()->send();
    }

    protected function restaurantTabSchema(): array
    {
        $toggles = [];
        foreach (RestaurantSettings::FEATURES as $key => $meta) {
            $toggles[] = Forms\Components\Toggle::make("restaurant_enable_{$key}")
                ->label($meta['label'])
                ->helperText($meta['description'])
                ->default((bool) $meta['default'])
                ->inline(false);
        }

        return [
            Forms\Components\Section::make('Features del módulo Restaurante')
                ->description('Habilita o deshabilita cada funcionalidad. Lo que apagues acá desaparece tanto del menú lateral como del POS de restaurante. No borra datos — solo oculta la UI.')
                ->columns(2)
                ->schema($toggles),

            Forms\Components\Section::make('Cuentas contables')
                ->description('Configuración contable específica del módulo.')
                ->schema([
                    Forms\Components\Select::make('restaurant_tip_payable_account_id')
                        ->label('Cuenta de propinas por pagar (mesero/staff)')
                        ->helperText('Al cobrar una orden con propina, el sistema crea un asiento DR Caja / CR esta cuenta — así la propina queda registrada como pasivo a pagar al staff. Si no se configura, las propinas siguen contándose en order.tip_amount pero NO se genera asiento. Default sugerido: cuenta 218505.')
                        ->options(fn () => \App\Models\Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '21%')   // pasivos
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name])
                            ->all())
                        ->searchable()
                        ->placeholder('— Sin asiento de propina —'),
                ]),

            // Bloque visual de "submit" — el botón real está fuera del form
            // (ver settings.blade.php). El Filament Action dentro del tab no
            // dispara saveRestaurant() de forma confiable.
            Forms\Components\Placeholder::make('save_restaurant_hint')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="display:flex; justify-content:flex-end; padding-top:8px;">'
                    .'<button type="button" wire:click="saveRestaurant" '
                    .'style="padding:10px 20px; background:#6366f1; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:6px;">'
                    .'<svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
                    .'Guardar configuración Restaurante'
                    .'</button></div>'
                )),
        ];
    }

    public function saveRestaurant(): void
    {
        if (! auth()->user()->can('company.settings')) {
            $this->errorNotif('Sin permiso para editar configuración');
            return;
        }

        // Leer directo de $this->data (wire:model lo actualiza en cada toggle).
        $company = $this->getCompany();
        $settings = $company->settings ?? [];

        $restaurant = $settings['restaurant'] ?? [];
        $debug = [];
        foreach (array_keys(RestaurantSettings::FEATURES) as $key) {
            $stateKey = 'restaurant_enable_'.$key;
            if (array_key_exists($stateKey, $this->data)) {
                $value = (bool) $this->data[$stateKey];
                $source = 'data';
            } else {
                $value = (bool) ($restaurant['enable_'.$key] ?? RestaurantSettings::FEATURES[$key]['default']);
                $source = 'fallback';
            }
            $restaurant['enable_'.$key] = $value;
            $debug[$key] = ['value' => $value, 'source' => $source];
        }

        // Cuenta de propinas por pagar (puede ser null = sin asiento)
        $tipAccount = $this->data['restaurant_tip_payable_account_id'] ?? null;
        $restaurant['tip_payable_account_id'] = $tipAccount ? (int) $tipAccount : null;

        $settings['restaurant'] = $restaurant;

        \Log::info('[Settings.saveRestaurant] writing', [
            'company_id' => $company->id,
            'restaurant_settings' => $restaurant,
            'sources' => $debug,
            'data_has_restaurant_keys' => array_filter(array_keys($this->data), fn ($k) => str_starts_with($k, 'restaurant_enable_')),
        ]);

        $company->update(['settings' => $settings]);

        // Refrescar singleton de empresa por si hay re-render que usa la version vieja
        app(\App\Support\CurrentCompany::class)->set($company->fresh());

        Notification::make()->title('Configuración de Restaurante guardada')->success()->send();
    }

    // ================================================================
    // PROMOCIONES
    // ================================================================

    protected function promotionsTabSchema(): array
    {
        // Master switch arriba destacado
        $masterToggle = Forms\Components\Toggle::make('promotions_enabled')
            ->label(PromotionsSettings::FEATURES['enabled']['label'])
            ->helperText(PromotionsSettings::FEATURES['enabled']['description'])
            ->live()
            ->default((bool) PromotionsSettings::FEATURES['enabled']['default'])
            ->inline(false);

        // Sub-toggles — solo visibles si master esta ON
        $subToggles = [];
        foreach (PromotionsSettings::FEATURES as $key => $meta) {
            if ($key === 'enabled') continue;
            $subToggles[] = Forms\Components\Toggle::make("promotions_{$key}")
                ->label($meta['label'])
                ->helperText($meta['description'])
                ->default((bool) $meta['default'])
                ->inline(false);
        }

        return [
            Forms\Components\Section::make('Módulo Promociones')
                ->description('Activa el motor de promociones y descuentos automáticos. Una vez encendido, podrás crear promociones desde "Ventas → Promociones" y se aplicarán automáticamente al carrito en el POS según sus condiciones.')
                ->schema([$masterToggle]),

            Forms\Components\Section::make('Tipos de promoción habilitados')
                ->description('Apaga los que no uses para que no aparezcan en el formulario de creación. No afecta promociones ya existentes — solo oculta el tipo al crear nuevas.')
                ->columns(2)
                ->schema($subToggles)
                ->visible(fn (Forms\Get $get) => (bool) $get('promotions_enabled')),

            Forms\Components\Placeholder::make('save_promotions_hint')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="display:flex; justify-content:flex-end; padding-top:8px;">'
                    .'<button type="button" wire:click="savePromotions" '
                    .'style="padding:10px 20px; background:#6366f1; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:6px;">'
                    .'<svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
                    .'Guardar configuración Promociones'
                    .'</button></div>'
                )),
        ];
    }

    public function savePromotions(): void
    {
        if (! auth()->user()->can('company.settings')) {
            $this->errorNotif('Sin permiso para editar configuración');
            return;
        }

        $company = $this->getCompany();
        $settings = $company->settings ?? [];
        $promotions = $settings['promotions'] ?? [];

        foreach (array_keys(PromotionsSettings::FEATURES) as $key) {
            $stateKey = 'promotions_'.$key;
            $value = array_key_exists($stateKey, $this->data)
                ? (bool) $this->data[$stateKey]
                : (bool) ($promotions[$key] ?? PromotionsSettings::FEATURES[$key]['default']);
            $promotions[$key] = $value;
        }

        $settings['promotions'] = $promotions;
        $company->update(['settings' => $settings]);

        app(\App\Support\CurrentCompany::class)->set($company->fresh());

        Notification::make()->title('Configuración de Promociones guardada')->success()->send();
    }

    // ================================================================
    // GIFT CARDS
    // ================================================================

    protected function giftCardsTabSchema(): array
    {
        $masterToggle = Forms\Components\Toggle::make('gift_cards_enabled')
            ->label(GiftCardsSettings::FEATURES['enabled']['label'])
            ->helperText(GiftCardsSettings::FEATURES['enabled']['description'])
            ->live()
            ->default((bool) GiftCardsSettings::FEATURES['enabled']['default'])
            ->inline(false);

        // Sub-features — toggle (default), input numerico (expiry) o select de cuenta (liability)
        $subFields = [];
        foreach (GiftCardsSettings::FEATURES as $key => $meta) {
            if ($key === 'enabled') continue;
            if ($key === 'default_expiry_months') {
                $subFields[] = Forms\Components\TextInput::make("gift_cards_{$key}")
                    ->label($meta['label'])
                    ->helperText($meta['description'])
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(120)
                    ->default((int) $meta['default'])
                    ->suffix('meses');
                continue;
            }
            if ($key === 'liability_account_id') {
                $subFields[] = Forms\Components\Select::make("gift_cards_{$key}")
                    ->label($meta['label'])
                    ->helperText($meta['description'])
                    ->options(fn () => \App\Models\Account::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->where('accepts_movements', true)
                        ->where('active', true)
                        ->where('code', 'like', '24%')   // pasivos a corto plazo
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name])
                        ->all())
                    ->searchable()
                    ->placeholder('— Usar 240825 por defecto —');
                continue;
            }
            $subFields[] = Forms\Components\Toggle::make("gift_cards_{$key}")
                ->label($meta['label'])
                ->helperText($meta['description'])
                ->default((bool) $meta['default'])
                ->inline(false);
        }

        return [
            Forms\Components\Section::make('Módulo Gift Cards / Bonos Regalo')
                ->description('Permite vender y redimir tarjetas regalo desde el POS. Una vez activo, aparecerá un Resource "Tarjetas Regalo" en el menú lateral.')
                ->schema([$masterToggle]),

            Forms\Components\Section::make('Comportamiento')
                ->description('Configura el comportamiento default al vender y redimir tarjetas.')
                ->columns(2)
                ->schema($subFields)
                ->visible(fn (Forms\Get $get) => (bool) $get('gift_cards_enabled')),

            Forms\Components\Placeholder::make('save_gift_cards_hint')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="display:flex; justify-content:flex-end; padding-top:8px;">'
                    .'<button type="button" wire:click="saveGiftCards" '
                    .'style="padding:10px 20px; background:#6366f1; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:6px;">'
                    .'<svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
                    .'Guardar configuración Gift Cards'
                    .'</button></div>'
                )),
        ];
    }

    public function saveGiftCards(): void
    {
        if (! auth()->user()->can('company.settings')) {
            $this->errorNotif('Sin permiso para editar configuración');
            return;
        }

        $company = $this->getCompany();
        $settings = $company->settings ?? [];
        $gc = $settings['gift_cards'] ?? [];

        foreach (array_keys(GiftCardsSettings::FEATURES) as $key) {
            $stateKey = 'gift_cards_'.$key;
            if (! array_key_exists($stateKey, $this->data)) {
                $gc[$key] = $gc[$key] ?? GiftCardsSettings::FEATURES[$key]['default'];
                continue;
            }
            // Cast por tipo de feature:
            //  - default_expiry_months: int
            //  - liability_account_id: int|null (acepta vacio para fallback)
            //  - resto: bool
            if ($key === 'default_expiry_months') {
                $gc[$key] = (int) $this->data[$stateKey];
            } elseif ($key === 'liability_account_id') {
                $gc[$key] = $this->data[$stateKey] ? (int) $this->data[$stateKey] : null;
            } else {
                $gc[$key] = (bool) $this->data[$stateKey];
            }
        }

        $settings['gift_cards'] = $gc;
        $company->update(['settings' => $settings]);

        app(\App\Support\CurrentCompany::class)->set($company->fresh());

        // Si se activo el modulo, asegurar que el producto especial 'Tarjeta
        // Regalo' exista (idempotente). Si se desactiva, lo dejamos por si
        // se reactiva luego — no se borran productos automaticamente.
        if (! empty($gc['enabled'])) {
            app(\App\Services\GiftCards\GiftCardProductProvisioner::class)->provision($company);
        }

        Notification::make()->title('Configuración de Gift Cards guardada')->success()->send();
    }

    // ================================================================
    // COMISIONES
    // ================================================================

    protected function commissionsTabSchema(): array
    {
        $masterToggle = Forms\Components\Toggle::make('commissions_enabled')
            ->label(CommissionsSettings::FEATURES['enabled']['label'])
            ->helperText(CommissionsSettings::FEATURES['enabled']['description'])
            ->live()
            ->default((bool) CommissionsSettings::FEATURES['enabled']['default'])
            ->inline(false);

        return [
            Forms\Components\Section::make('Módulo Comisiones')
                ->description('Calcula comisiones por vendedor sobre las ventas y permite liquidarlas por período. Las reglas de % por vendedor se configuran en Ventas → Comisiones.')
                ->schema([$masterToggle]),

            Forms\Components\Section::make('Parámetros de cálculo')
                ->columns(2)
                ->visible(fn (Forms\Get $get) => (bool) $get('commissions_enabled'))
                ->schema([
                    Forms\Components\Select::make('commissions_base')
                        ->label(CommissionsSettings::FEATURES['base']['label'])
                        ->helperText(CommissionsSettings::FEATURES['base']['description'])
                        ->options([
                            CommissionsSettings::BASE_SUBTOTAL => 'Subtotal (sin IVA)',
                            CommissionsSettings::BASE_TOTAL => 'Total (con IVA)',
                            CommissionsSettings::BASE_PROFIT => 'Utilidad (venta − costo)',
                        ])
                        ->default(CommissionsSettings::BASE_SUBTOTAL)
                        ->required(),

                    Forms\Components\Select::make('commissions_causation')
                        ->label(CommissionsSettings::FEATURES['causation']['label'])
                        ->helperText(CommissionsSettings::FEATURES['causation']['description'])
                        ->options([
                            CommissionsSettings::CAUSATION_COLLECTED => 'Al cobrar (factura pagada totalmente)',
                            CommissionsSettings::CAUSATION_INVOICED => 'Al facturar (al emitir la venta)',
                        ])
                        ->default(CommissionsSettings::CAUSATION_COLLECTED)
                        ->required(),
                ]),

            Forms\Components\Section::make('Cuentas contables')
                ->description('Al liquidar comisiones se genera el asiento: débito a gasto, crédito a comisiones por pagar.')
                ->columns(2)
                ->visible(fn (Forms\Get $get) => (bool) $get('commissions_enabled'))
                ->schema([
                    Forms\Components\Select::make('commissions_expense_account_id')
                        ->label(CommissionsSettings::FEATURES['expense_account_id']['label'])
                        ->helperText(CommissionsSettings::FEATURES['expense_account_id']['description'])
                        ->options(fn () => \App\Models\Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)->where('active', true)
                            ->where(fn ($q) => $q->where('code', 'like', '51%')->orWhere('code', 'like', '52%')->orWhere('code', 'like', '53%'))
                            ->orderBy('code')->get()
                            ->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name])->all())
                        ->searchable()
                        ->placeholder('— Usar 530515 por defecto —'),

                    Forms\Components\Select::make('commissions_payable_account_id')
                        ->label(CommissionsSettings::FEATURES['payable_account_id']['label'])
                        ->helperText(CommissionsSettings::FEATURES['payable_account_id']['description'])
                        ->options(fn () => \App\Models\Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)->where('active', true)
                            ->where('code', 'like', '23%')
                            ->orderBy('code')->get()
                            ->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name])->all())
                        ->searchable()
                        ->placeholder('— Usar 233520 por defecto —'),
                ]),

            Forms\Components\Placeholder::make('save_commissions_hint')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="display:flex; justify-content:flex-end; padding-top:8px;">'
                    .'<button type="button" wire:click="saveCommissions" '
                    .'style="padding:10px 20px; background:#6366f1; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:6px;">'
                    .'<svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
                    .'Guardar configuración Comisiones'
                    .'</button></div>'
                )),
        ];
    }

    public function saveCommissions(): void
    {
        if (! auth()->user()->can('company.settings')) {
            $this->errorNotif('Sin permiso para editar configuración');
            return;
        }

        $company = $this->getCompany();
        $settings = $company->settings ?? [];
        $c = $settings['commissions'] ?? [];

        foreach (array_keys(CommissionsSettings::FEATURES) as $key) {
            $stateKey = 'commissions_'.$key;
            if (! array_key_exists($stateKey, $this->data)) {
                $c[$key] = $c[$key] ?? CommissionsSettings::FEATURES[$key]['default'];
                continue;
            }
            if ($key === 'enabled') {
                $c[$key] = (bool) $this->data[$stateKey];
            } elseif (in_array($key, ['expense_account_id', 'payable_account_id'], true)) {
                $c[$key] = $this->data[$stateKey] ? (int) $this->data[$stateKey] : null;
            } else {
                $c[$key] = $this->data[$stateKey]; // base, causation (strings)
            }
        }

        $settings['commissions'] = $c;
        $company->update(['settings' => $settings]);
        app(\App\Support\CurrentCompany::class)->set($company->fresh());

        Notification::make()->title('Configuración de Comisiones guardada')->success()->send();
    }

    // ================================================================
    // CITAS / AGENDAMIENTO
    // ================================================================

    protected function appointmentsTabSchema(): array
    {
        $masterToggle = Forms\Components\Toggle::make('appointments_enabled')
            ->label(AppointmentsSettings::FEATURES['enabled']['label'])
            ->helperText(AppointmentsSettings::FEATURES['enabled']['description'])
            ->live()
            ->default((bool) AppointmentsSettings::FEATURES['enabled']['default'])
            ->inline(false);

        return [
            Forms\Components\Section::make('Módulo Citas')
                ->description('Activa la agenda de citas. Una vez encendido, aparecerá "Citas" (agenda y calendario) en el menú lateral para agendar servicios a clientes con un profesional asignado.')
                ->schema([$masterToggle]),

            Forms\Components\Section::make('Comportamiento')
                ->columns(2)
                ->visible(fn (Forms\Get $get) => (bool) $get('appointments_enabled'))
                ->schema([
                    Forms\Components\TextInput::make('appointments_default_duration_minutes')
                        ->label(AppointmentsSettings::FEATURES['default_duration_minutes']['label'])
                        ->helperText(AppointmentsSettings::FEATURES['default_duration_minutes']['description'])
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(480)
                        ->step(5)
                        ->default((int) AppointmentsSettings::FEATURES['default_duration_minutes']['default'])
                        ->suffix('min'),

                    Forms\Components\Toggle::make('appointments_require_client')
                        ->label(AppointmentsSettings::FEATURES['require_client']['label'])
                        ->helperText(AppointmentsSettings::FEATURES['require_client']['description'])
                        ->default((bool) AppointmentsSettings::FEATURES['require_client']['default'])
                        ->inline(false),

                    Forms\Components\Toggle::make('appointments_allow_overlap')
                        ->label(AppointmentsSettings::FEATURES['allow_overlap']['label'])
                        ->helperText(AppointmentsSettings::FEATURES['allow_overlap']['description'])
                        ->default((bool) AppointmentsSettings::FEATURES['allow_overlap']['default'])
                        ->inline(false),
                ]),

            Forms\Components\Placeholder::make('save_appointments_hint')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="display:flex; justify-content:flex-end; padding-top:8px;">'
                    .'<button type="button" wire:click="saveAppointments" '
                    .'style="padding:10px 20px; background:#6366f1; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:6px;">'
                    .'<svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
                    .'Guardar configuración Citas'
                    .'</button></div>'
                )),
        ];
    }

    public function saveAppointments(): void
    {
        if (! auth()->user()->can('company.settings')) {
            $this->errorNotif('Sin permiso para editar configuración');
            return;
        }

        $company = $this->getCompany();
        $settings = $company->settings ?? [];
        $a = $settings['appointments'] ?? [];

        foreach (array_keys(AppointmentsSettings::FEATURES) as $key) {
            $stateKey = 'appointments_'.$key;
            if (! array_key_exists($stateKey, $this->data)) {
                $a[$key] = $a[$key] ?? AppointmentsSettings::FEATURES[$key]['default'];
                continue;
            }
            if ($key === 'default_duration_minutes') {
                $a[$key] = (int) $this->data[$stateKey];
            } else {
                $a[$key] = (bool) $this->data[$stateKey];
            }
        }

        $settings['appointments'] = $a;
        $company->update(['settings' => $settings]);
        app(\App\Support\CurrentCompany::class)->set($company->fresh());

        Notification::make()->title('Configuración de Citas guardada')->success()->send();
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
