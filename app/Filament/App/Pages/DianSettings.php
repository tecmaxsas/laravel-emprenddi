<?php

namespace App\Filament\App\Pages;

use App\Models\Dian\CompanyConfig;
use App\Models\Dian\DocumentType;
use App\Models\Dian\Municipality;
use App\Models\Dian\OrganizationType;
use App\Models\Dian\RegimeType;
use App\Models\Dian\TaxLiability;
use App\Services\Dian\DianApiClient;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class DianSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Facturación Electrónica DIAN';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $title = 'Facturación Electrónica DIAN';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.app.pages.dian-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('dian.manage');
    }

    public function mount(): void
    {
        $config = $this->config();

        $this->data = [
            'dian_document_type_id' => $config->dian_document_type_id,
            'dian_organization_type_id' => $config->dian_organization_type_id,
            'dian_regime_type_id' => $config->dian_regime_type_id,
            'dian_tax_liability_id' => $config->dian_tax_liability_id,
            'dian_municipality_id' => $config->dian_municipality_id,
            'merchant_registration' => $config->merchant_registration,
        ];

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('dian')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Datos de la empresa')
                            ->icon('heroicon-o-building-office-2')
                            ->schema($this->companyTabSchema()),

                        Forms\Components\Tabs\Tab::make('Software')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Placeholder::make('software_pending')
                                    ->label('')
                                    ->content('Disponible después de registrar la empresa en DIAN. (Próximamente)'),
                            ]),

                        Forms\Components\Tabs\Tab::make('Certificado')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Forms\Components\Placeholder::make('cert_pending')
                                    ->label('')
                                    ->content('Disponible después de configurar el software DIAN. (Próximamente)'),
                            ]),

                        Forms\Components\Tabs\Tab::make('Resoluciones')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\Placeholder::make('res_pending')
                                    ->label('')
                                    ->content('Disponible después de cargar el certificado .p12. (Próximamente)'),
                            ]),

                        Forms\Components\Tabs\Tab::make('Pruebas y ambiente')
                            ->icon('heroicon-o-beaker')
                            ->schema([
                                Forms\Components\Placeholder::make('env_pending')
                                    ->label('')
                                    ->content('Disponible después de configurar al menos una resolución. (Próximamente)'),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ])
            ->statePath('data');
    }

    protected function companyTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Identificación tributaria')
                ->description('Datos que se envían a DIAN al registrar la empresa.')
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('nit_display')
                        ->label('NIT')
                        ->content(fn () => auth()->user()->company?->fullNit() ?? '—'),

                    Forms\Components\Placeholder::make('legal_name_display')
                        ->label('Razón social')
                        ->content(fn () => auth()->user()->company?->legal_name
                            ?? auth()->user()->company?->name
                            ?? '—')
                        ->columnSpan(2),

                    Forms\Components\Select::make('dian_document_type_id')
                        ->label('Tipo de documento')
                        ->options(fn () => DocumentType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->native(false)
                        ->searchable(),

                    Forms\Components\Select::make('dian_organization_type_id')
                        ->label('Tipo de organización')
                        ->options(fn () => OrganizationType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('dian_regime_type_id')
                        ->label('Régimen')
                        ->options(fn () => RegimeType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('dian_tax_liability_id')
                        ->label('Responsabilidad tributaria')
                        ->options(fn () => TaxLiability::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('merchant_registration')
                        ->label('Matrícula mercantil')
                        ->maxLength(50)
                        ->placeholder('Cámara de comercio'),
                ]),

            Forms\Components\Section::make('Ubicación física')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('dian_municipality_id')
                        ->label('Municipio')
                        ->options(fn () => Municipality::query()
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id'))
                        ->getSearchResultsUsing(fn (string $search) => Municipality::query()
                            ->where('name', 'ilike', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Municipality::find($value)?->fullName())
                        ->searchable()
                        ->required()
                        ->columnSpan(2),

                    Forms\Components\Placeholder::make('address_display')
                        ->label('Dirección')
                        ->content(fn () => auth()->user()->company?->address ?? '—')
                        ->columnSpan(3),
                ]),
        ];
    }

    public function saveTab1(): void
    {
        $state = $this->form->getState();
        $company = auth()->user()->company;

        if (! $company) {
            Notification::make()
                ->title('No hay empresa asociada al usuario')
                ->danger()
                ->send();
            return;
        }

        if (! $company->nit || ! $company->dv) {
            Notification::make()
                ->title('La empresa debe tener NIT y dígito de verificación')
                ->body('Configura primero los datos básicos de la empresa.')
                ->danger()
                ->send();
            return;
        }

        DB::transaction(function () use ($state, $company) {
            CompanyConfig::updateOrCreate(
                ['company_id' => $company->id],
                [
                    'dian_document_type_id' => $state['dian_document_type_id'],
                    'dian_organization_type_id' => $state['dian_organization_type_id'],
                    'dian_regime_type_id' => $state['dian_regime_type_id'],
                    'dian_tax_liability_id' => $state['dian_tax_liability_id'],
                    'dian_municipality_id' => $state['dian_municipality_id'],
                    'merchant_registration' => $state['merchant_registration'] ?? null,
                ],
            );
        });

        $config = $this->config()->refresh();

        $payload = [
            'type_document_identification_id' => $config->dian_document_type_id,
            'type_organization_id' => $config->dian_organization_type_id,
            'type_regime_id' => $config->dian_regime_type_id,
            'type_liability_id' => $config->dian_tax_liability_id,
            'business_name' => $company->legal_name ?: $company->name,
            'merchant_registration' => $config->merchant_registration ?: '0000000-00',
            'municipality_id' => $config->dian_municipality_id,
            'address' => $company->address ?: 'Sin dirección',
            'phone' => (int) preg_replace('/\D/', '', (string) ($company->phone ?: '0')),
            'email' => $company->email ?: 'sin@email.com',
        ];

        $result = (new DianApiClient($config))->registerCompany(
            $company->nit,
            $company->dv,
            $payload,
        );

        if ($result['ok']) {
            // El API entrega el token per-company que se usa para todos los
            // demás endpoints. El payload exacto puede venir en distintas
            // formas — buscamos en los lugares más comunes.
            $token = $this->extractToken($result['data']);

            $config->update([
                'company_registered' => true,
                'api_token' => $token ?: $config->api_token,
            ]);

            Notification::make()
                ->title('Empresa registrada en DIAN')
                ->body('Ya puedes continuar con el tab de Software.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('No fue posible registrar la empresa en DIAN')
                ->body($result['error'] ?? 'Error desconocido')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * El response de registerCompany trae el token per-company. Distintas
     * versiones del API lo retornan en distintas keys — probamos las más
     * comunes en orden.
     */
    protected function extractToken(array $data): ?string
    {
        $candidates = [
            $data['token'] ?? null,
            $data['api_token'] ?? null,
            $data['data']['token'] ?? null,
            $data['data']['api_token'] ?? null,
            $data['company']['token'] ?? null,
            $data['user']['api_token'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function config(): CompanyConfig
    {
        return CompanyConfig::firstOrCreate(
            ['company_id' => auth()->user()->company_id],
            ['api_url' => 'https://apidian.emprenddi.com', 'environment' => CompanyConfig::ENV_TEST],
        );
    }
}
