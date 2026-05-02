<?php

namespace App\Filament\App\Pages\Auth;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\DianDvCalculator;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Register as BaseRegister;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class Register extends BaseRegister
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make($this->getRegistrationSteps())
                    ->submitAction($this->getRegisterFormAction())
                    ->skippable(false)
                    ->nextAction(fn ($action) => $action->label('Siguiente'))
                    ->previousAction(fn ($action) => $action->label('Anterior')),
            ])
            ->statePath('data');
    }

    protected function getRegistrationSteps(): array
    {
        return [
            Step::make('config')
                ->label('Configuración inicial')
                ->icon('heroicon-o-cog-6-tooth')
                ->schema([
                    Placeholder::make('intro')
                        ->label('')
                        ->content('Cuéntanos cómo opera tu empresa para configurar Emprenddi correctamente.'),

                    Select::make('organization_type')
                        ->label('Tipo de organización')
                        ->options([
                            'juridica' => 'Persona Jurídica y asimiladas',
                            'natural' => 'Persona Natural',
                        ])
                        ->default('juridica')
                        ->required()
                        ->native(false),

                    Select::make('accounting_method')
                        ->label('Método contable')
                        ->options([
                            'niif_pymes' => 'NIIF para PYMES',
                            'niif_full' => 'NIIF Plenas / Full',
                        ])
                        ->default('niif_pymes')
                        ->required()
                        ->native(false)
                        ->helperText('NIIF Pymes aplica a la mayoría de empresas pequeñas y medianas.'),

                    Select::make('inventory_method')
                        ->label('Método de valoración de inventario')
                        ->options([
                            'weighted_average' => 'Promedio Ponderado',
                            'fifo' => 'PEPS (FIFO) — Primero en entrar, primero en salir',
                            'lifo' => 'UEPS (LIFO) — Último en entrar, primero en salir',
                        ])
                        ->default('weighted_average')
                        ->required()
                        ->native(false),

                    Select::make('currency')
                        ->label('Moneda principal')
                        ->options([
                            'COP' => 'Peso Colombiano (COP)',
                            'USD' => 'Dólar Estadounidense (USD)',
                        ])
                        ->default('COP')
                        ->required()
                        ->native(false),
                ]),

            Step::make('company')
                ->label('Datos de la Compañía')
                ->icon('heroicon-o-building-office-2')
                ->schema([
                    Group::make()->schema([
                        TextInput::make('company_name')
                            ->label('Nombre comercial')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        TextInput::make('legal_name')
                            ->label('Razón social')
                            ->maxLength(255)
                            ->helperText('Si aplica. Déjalo vacío si es igual al nombre comercial.')
                            ->columnSpan(2),

                        Select::make('document_type')
                            ->label('Tipo de Documento')
                            ->options([
                                'nit' => 'NIT',
                                'cc' => 'Cédula de Ciudadanía',
                                'ce' => 'Cédula de Extranjería',
                                'pasaporte' => 'Pasaporte',
                                'rut' => 'RUT',
                            ])
                            ->default('nit')
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                if ($state === 'nit' && $get('nit')) {
                                    $set('dv', DianDvCalculator::calculate($get('nit')));
                                } elseif ($state !== 'nit') {
                                    $set('dv', null);
                                }
                            }),

                        TextInput::make('nit')
                            ->label('Número de Identificación')
                            ->required()
                            ->numeric()
                            ->maxLength(15)
                            ->live(onBlur: true)
                            ->unique('companies', 'nit', ignoreRecord: true)
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                if ($get('document_type') === 'nit' && $state) {
                                    $set('dv', DianDvCalculator::calculate((string) $state));
                                }
                            }),

                        TextInput::make('dv')
                            ->label('Dígito de verificación')
                            ->disabled()
                            ->dehydrated()
                            ->maxLength(1)
                            ->helperText('Calculado automáticamente'),

                        Select::make('regime_type')
                            ->label('Tipo de Régimen')
                            ->options([
                                'comun' => 'Responsable de IVA',
                                'no_responsable_iva' => 'No Responsable de IVA',
                                'gran_contribuyente' => 'Gran Contribuyente',
                                'simplificado' => 'Régimen Simplificado',
                            ])
                            ->default('comun')
                            ->required()
                            ->native(false)
                            ->columnSpan(2),

                        TextInput::make('city')
                            ->label('Ciudad')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('department')
                            ->label('Departamento')
                            ->maxLength(100),

                        TextInput::make('address')
                            ->label('Dirección')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        TextInput::make('phone')
                            ->label('Teléfono')
                            ->required()
                            ->tel()
                            ->maxLength(20)
                            ->prefix('+57')
                            ->columnSpan(2),
                    ])->columns(2),
                ]),

            Step::make('admin')
                ->label('Usuario Administrador')
                ->icon('heroicon-o-user')
                ->schema([
                    Group::make()->schema([
                        TextInput::make('admin_first_name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('admin_last_name')
                            ->label('Apellido')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('admin_email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique('users', 'email', ignoreRecord: true)
                            ->columnSpan(2),

                        TextInput::make('admin_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(Password::default())
                            ->same('admin_password_confirmation')
                            ->validationAttribute('contraseña')
                            ->columnSpan(2),

                        TextInput::make('admin_password_confirmation')
                            ->label('Confirmar Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false)
                            ->columnSpan(2),
                    ])->columns(2),

                    Section::make('Términos y condiciones')
                        ->description('Para crear tu cuenta debes aceptar nuestros términos.')
                        ->schema([
                            Checkbox::make('accept_terms')
                                ->label(fn () => new \Illuminate\Support\HtmlString(
                                    'He leído y acepto: <a href="#" target="_blank" class="text-primary-600 underline">Términos y Condiciones</a>'
                                ))
                                ->required()
                                ->accepted()
                                ->validationMessages(['accepted' => 'Debes aceptar los Términos y Condiciones.']),

                            Checkbox::make('accept_privacy')
                                ->label(fn () => new \Illuminate\Support\HtmlString(
                                    'He leído y acepto: <a href="#" target="_blank" class="text-primary-600 underline">Política de Privacidad</a>'
                                ))
                                ->required()
                                ->accepted()
                                ->validationMessages(['accepted' => 'Debes aceptar la Política de Privacidad.']),

                            Checkbox::make('accept_data_treatment')
                                ->label(fn () => new \Illuminate\Support\HtmlString(
                                    'He leído y acepto: <a href="#" target="_blank" class="text-primary-600 underline">Política de Tratamiento de Datos</a>'
                                ))
                                ->required()
                                ->accepted()
                                ->validationMessages(['accepted' => 'Debes aceptar la Política de Tratamiento de Datos.']),

                            Checkbox::make('marketing_opt_in')
                                ->label('Acepto recibir ofertas y comunicaciones de marketing (opcional)'),
                        ]),
                ]),

            Step::make('confirm')
                ->label('Confirmación')
                ->icon('heroicon-o-check-circle')
                ->schema([
                    Placeholder::make('summary_intro')
                        ->label('')
                        ->content('Revisa los datos antes de crear tu cuenta. Podrás editar la información de tu empresa después desde el panel.'),

                    Section::make('Resumen')->schema([
                        Placeholder::make('summary_company')
                            ->label('Compañía')
                            ->content(fn (Get $get) => sprintf(
                                '%s — %s %s%s',
                                $get('company_name') ?: '(sin nombre)',
                                strtoupper($get('document_type') ?? ''),
                                $get('nit') ?: '',
                                $get('dv') !== null ? '-'.$get('dv') : ''
                            )),

                        Placeholder::make('summary_location')
                            ->label('Ubicación')
                            ->content(fn (Get $get) => trim(
                                ($get('address') ?: '').' · '.
                                ($get('city') ?: '').
                                ($get('department') ? ', '.$get('department') : '')
                            )),

                        Placeholder::make('summary_admin')
                            ->label('Administrador')
                            ->content(fn (Get $get) => trim(
                                ($get('admin_first_name') ?: '').' '.
                                ($get('admin_last_name') ?: '').
                                ' <'.($get('admin_email') ?: '').'>'
                            )),

                        Placeholder::make('summary_config')
                            ->label('Configuración')
                            ->content(fn (Get $get) => sprintf(
                                'Régimen: %s · Contabilidad: %s · Inventario: %s · Moneda: %s',
                                $this->labelFor('regime_type', $get('regime_type')),
                                $this->labelFor('accounting_method', $get('accounting_method')),
                                $this->labelFor('inventory_method', $get('inventory_method')),
                                $get('currency') ?: 'COP'
                            )),
                    ]),
                ]),
        ];
    }

    protected function handleRegistration(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'legal_name' => $data['legal_name'] ?: $data['company_name'],
                'document_type' => $data['document_type'],
                'organization_type' => $data['organization_type'],
                'nit' => $data['nit'],
                'dv' => $data['dv'] ?? null,
                'regime_type' => $data['regime_type'],
                'accounting_method' => $data['accounting_method'],
                'inventory_method' => $data['inventory_method'],
                'currency' => $data['currency'] ?? 'COP',
                'address' => $data['address'],
                'city' => $data['city'],
                'department' => $data['department'] ?? null,
                'country' => 'CO',
                'phone' => $data['phone'],
                'phone_country_code' => '+57',
                'timezone' => 'America/Bogota',
                'active' => true,
                'trial_ends_at' => now()->addDays(30),
            ]);

            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['admin_first_name'],
                'last_name' => $data['admin_last_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'is_super_admin' => false,
                'active' => true,
                'accepted_terms_at' => now(),
                'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
                'email_verified_at' => now(),
            ]);

            $user->assignRole('admin');

            $trialPlan = Plan::where('slug', 'free-trial')->first()
                ?? Plan::where('is_active', true)->orderBy('sort_order')->first();

            if ($trialPlan) {
                $trialDays = $trialPlan->trial_days > 0 ? $trialPlan->trial_days : 30;

                Subscription::create([
                    'company_id' => $company->id,
                    'plan_id' => $trialPlan->id,
                    'status' => Subscription::STATUS_TRIAL,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($trialDays),
                    'price_paid' => 0,
                    'currency' => $trialPlan->currency,
                    'billing_cycle' => $trialPlan->billing_cycle,
                    'created_by_user_id' => $user->id,
                    'notes' => 'Auto-creada al registrar la empresa.',
                ]);
            }

            Notification::make()
                ->success()
                ->title('¡Bienvenido a Emprenddi!')
                ->body("Tu cuenta de {$company->name} fue creada correctamente.")
                ->send();

            return $user;
        });
    }

    protected function labelFor(string $field, ?string $value): string
    {
        $maps = [
            'regime_type' => [
                'comun' => 'Responsable de IVA',
                'no_responsable_iva' => 'No Responsable de IVA',
                'gran_contribuyente' => 'Gran Contribuyente',
                'simplificado' => 'Simplificado',
            ],
            'accounting_method' => [
                'niif_pymes' => 'NIIF Pymes',
                'niif_full' => 'NIIF Full',
            ],
            'inventory_method' => [
                'weighted_average' => 'Promedio Ponderado',
                'fifo' => 'FIFO',
                'lifo' => 'LIFO',
            ],
        ];

        return $maps[$field][$value] ?? ($value ?: '—');
    }

    public function getMaxWidth(): MaxWidth | string | null
    {
        return MaxWidth::FourExtraLarge;
    }

    public function getTitle(): string
    {
        return 'Crear cuenta en Emprenddi';
    }

    public function getHeading(): string
    {
        return 'Bienvenido';
    }

    public function getSubheading(): ?string
    {
        return 'Crea tu cuenta y empieza a usar Emprenddi en minutos.';
    }
}
