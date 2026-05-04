<?php

namespace App\Filament\App\Pages;

use App\Models\Dian\CompanyConfig;
use App\Models\Dian\DocumentType;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Municipality;
use App\Models\Dian\OrganizationType;
use App\Models\Dian\RegimeType;
use App\Models\Dian\Resolution;
use App\Models\Dian\TaxLiability;
use App\Models\Location;
use App\Services\Dian\DianApiClient;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

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
            // Tab 1
            'dian_document_type_id' => $config->dian_document_type_id,
            'dian_organization_type_id' => $config->dian_organization_type_id,
            'dian_regime_type_id' => $config->dian_regime_type_id,
            'dian_tax_liability_id' => $config->dian_tax_liability_id,
            'dian_municipality_id' => $config->dian_municipality_id,
            'merchant_registration' => $config->merchant_registration,

            // Tab 2
            'software_id' => $config->software_id,
            'software_pin' => $config->software_pin,

            // Tab 3
            'certificate_password' => $config->certificate_password,

            // Tab 4 — campos para crear UNA nueva resolución
            'res_document_type_id' => 1,
            'res_prefix' => null,
            'res_resolution_number' => null,
            'res_resolution_date' => null,
            'res_technical_key' => null,
            'res_range_from' => null,
            'res_range_to' => null,
            'res_date_from' => null,
            'res_date_to' => null,

            // Tab 4 — asignación a sede
            'assign_resolution_id' => null,
            'assign_location_id' => null,
            'assign_current_consecutive' => null,

            // Tab 5
            'environment' => $config->environment ?: CompanyConfig::ENV_TEST,
            'test_set_id' => null,
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
                            ->schema($this->softwareTabSchema()),

                        Forms\Components\Tabs\Tab::make('Certificado')
                            ->icon('heroicon-o-shield-check')
                            ->schema($this->certificateTabSchema()),

                        Forms\Components\Tabs\Tab::make('Resoluciones')
                            ->icon('heroicon-o-document-text')
                            ->schema($this->resolutionsTabSchema()),

                        Forms\Components\Tabs\Tab::make('Pruebas y ambiente')
                            ->icon('heroicon-o-beaker')
                            ->schema($this->environmentTabSchema()),
                    ])
                    ->persistTabInQueryString(),
            ])
            ->statePath('data');
    }

    // ================================================================
    // TAB 1 — Datos de la empresa
    // ================================================================

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

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveTab1')
                    ->label('Guardar y registrar en DIAN')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->action('saveTab1'),
            ])->alignEnd(),
        ];
    }

    public function saveTab1(): void
    {
        $state = $this->form->getState();
        $company = auth()->user()->company;

        if (! $company) {
            $this->errorNotif('No hay empresa asociada al usuario');
            return;
        }
        if (! $company->nit || ! $company->dv) {
            $this->errorNotif('La empresa debe tener NIT y dígito de verificación', 'Configura primero los datos básicos de la empresa.');
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

        $result = (new DianApiClient($config))->registerCompany($company->nit, $company->dv, $payload);

        if ($result['ok']) {
            $token = $this->extractToken($result['data']);
            $config->update([
                'company_registered' => true,
                'api_token' => $token ?: $config->api_token,
            ]);
            $this->successNotif('Empresa registrada en DIAN', 'Continúa con el tab de Software.');
        } else {
            $this->errorNotif('No fue posible registrar la empresa en DIAN', $result['error'] ?? 'Error desconocido');
        }
    }

    // ================================================================
    // TAB 2 — Software
    // ================================================================

    protected function softwareTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Software DIAN')
                ->description('ID y PIN del software entregados por DIAN al habilitar la empresa.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('software_id')
                        ->label('ID Software')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('software_pin')
                        ->label('PIN')
                        ->password()
                        ->revealable()
                        ->required()
                        ->maxLength(100),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveTab2')
                    ->label('Guardar software en DIAN')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->action('saveTab2'),
            ])->alignEnd(),
        ];
    }

    public function saveTab2(): void
    {
        $state = $this->form->getState();
        $config = $this->config()->refresh();

        if (! $config->company_registered || ! $config->api_token) {
            $this->errorNotif('Primero registra la empresa', 'Completa el tab "Datos de la empresa".');
            return;
        }

        $config->update([
            'software_id' => $state['software_id'],
            'software_pin' => $state['software_pin'],
        ]);

        $config->refresh();

        $result = (new DianApiClient($config))->saveSoftware([
            'id' => $config->software_id,
            'pin' => $config->software_pin,
        ]);

        if ($result['ok']) {
            $config->update(['software_configured' => true]);
            $this->successNotif('Software configurado en DIAN', 'Continúa con el tab de Certificado.');
        } else {
            $this->errorNotif('No fue posible configurar el software en DIAN', $result['error'] ?? 'Error desconocido');
        }
    }

    // ================================================================
    // TAB 3 — Certificado
    // ================================================================

    protected function certificateTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Certificado digital (.p12)')
                ->description('Archivo .p12 emitido por una entidad certificadora autorizada por la ONAC.')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('cert_current')
                        ->label('Archivo actual')
                        ->content(fn () => $this->config()->certificate_filename
                            ?: 'Aún no se ha subido un certificado.'),

                    Forms\Components\FileUpload::make('certificate_file')
                        ->label('Subir nuevo .p12')
                        ->acceptedFileTypes(['application/x-pkcs12', 'application/octet-stream'])
                        ->maxSize(2048)
                        ->storeFiles(false)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('certificate_password')
                        ->label('Contraseña del certificado')
                        ->password()
                        ->revealable()
                        ->maxLength(200)
                        ->columnSpan(2),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveTab3')
                    ->label('Subir certificado a DIAN')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->action('saveTab3'),
            ])->alignEnd(),
        ];
    }

    public function saveTab3(): void
    {
        $state = $this->form->getState();
        $config = $this->config()->refresh();

        if (! $config->software_configured || ! $config->api_token) {
            $this->errorNotif('Primero configura el software', 'Completa el tab "Software".');
            return;
        }

        $file = $state['certificate_file'] ?? null;
        if (! $file) {
            $this->errorNotif('Adjunta el archivo .p12 antes de continuar');
            return;
        }

        $upload = is_array($file) ? array_values($file)[0] : $file;
        $base64 = base64_encode($upload->get());
        $filename = $upload->getClientOriginalName();

        $result = (new DianApiClient($config))->uploadCertificate([
            'certificate' => $base64,
            'password' => $state['certificate_password'],
        ]);

        if ($result['ok']) {
            $config->update([
                'certificate_filename' => $filename,
                'certificate_password' => $state['certificate_password'],
                'certificate_uploaded' => true,
            ]);
            $this->successNotif('Certificado subido a DIAN', 'Continúa con el tab de Resoluciones.');
        } else {
            $this->errorNotif('No fue posible subir el certificado a DIAN', $result['error'] ?? 'Error desconocido');
        }
    }

    // ================================================================
    // TAB 4 — Resoluciones
    // ================================================================

    protected function resolutionsTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Resoluciones registradas')
                ->description('Resoluciones DIAN cargadas para esta empresa.')
                ->schema([
                    Forms\Components\Placeholder::make('existing_resolutions')
                        ->label('')
                        ->content(fn () => $this->renderResolutionsList()),
                ]),

            Forms\Components\Section::make('Nueva resolución')
                ->description('Datos exactos de la resolución que entrega la DIAN al habilitar el rango de numeración.')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('res_document_type_id')
                        ->label('Tipo de documento')
                        ->options(Resolution::DOCUMENT_TYPES)
                        ->required()
                        ->native(false)
                        ->live(),

                    Forms\Components\TextInput::make('res_prefix')
                        ->label('Prefijo')
                        ->maxLength(10)
                        ->placeholder('SETP, FE, NC...'),

                    Forms\Components\TextInput::make('res_resolution_number')
                        ->label('Número de resolución')
                        ->maxLength(30)
                        ->placeholder('18760000001'),

                    Forms\Components\DatePicker::make('res_resolution_date')
                        ->label('Fecha de resolución'),

                    Forms\Components\TextInput::make('res_technical_key')
                        ->label('Clave técnica (solo FE)')
                        ->maxLength(100)
                        ->columnSpan(2)
                        ->visible(fn (Forms\Get $get) => (int) $get('res_document_type_id') === 1),

                    Forms\Components\TextInput::make('res_range_from')
                        ->label('Rango desde')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Forms\Components\TextInput::make('res_range_to')
                        ->label('Rango hasta')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Forms\Components\DatePicker::make('res_date_from')
                        ->label('Vigencia desde'),

                    Forms\Components\DatePicker::make('res_date_to')
                        ->label('Vigencia hasta'),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveResolution')
                    ->label('Guardar resolución y enviar a DIAN')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->action('saveResolution'),
            ])->alignEnd(),

            Forms\Components\Section::make('Asignar resolución a sede')
                ->description('El consecutivo inicial es editable: por defecto arranca en "Rango desde", pero si la resolución ya fue usada antes puedes empezar desde un número distinto.')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('assign_resolution_id')
                        ->label('Resolución')
                        ->options(fn () => Resolution::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->orderBy('document_type_id')
                            ->get()
                            ->mapWithKeys(fn (Resolution $r) => [
                                $r->id => trim(($r->prefix ?: '—').' · '.($r->document_type_name ?: 'Doc').' · '.$r->rangeLabel()),
                            ]))
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $resolution = $state ? Resolution::find($state) : null;
                            $set('assign_current_consecutive', $resolution?->range_from);
                        }),

                    Forms\Components\Select::make('assign_location_id')
                        ->label('Sede')
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('assign_current_consecutive')
                        ->label('Consecutivo actual')
                        ->helperText('Número que se usará en la próxima factura emitida desde esta sede.')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('assignToLocation')
                    ->label('Asignar a sede')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->action('assignToLocation'),
            ])->alignEnd(),
        ];
    }

    protected function renderResolutionsList(): HtmlString
    {
        $resolutions = Resolution::query()
            ->where('company_id', auth()->user()->company_id)
            ->withCount('locationAssignments')
            ->orderBy('document_type_id')
            ->get();

        if ($resolutions->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500">No hay resoluciones registradas todavía.</p>');
        }

        $rows = $resolutions->map(function (Resolution $r) {
            return sprintf(
                '<li class="text-sm"><strong>%s</strong> — %s · prefijo <code>%s</code> · rango %s · %d sedes asignadas</li>',
                e($r->document_type_name ?: ($r::DOCUMENT_TYPES[$r->document_type_id] ?? '?')),
                e($r->resolution_number ?: '—'),
                e($r->prefix ?: '—'),
                e($r->rangeLabel()),
                $r->location_assignments_count,
            );
        })->implode('');

        return new HtmlString('<ul class="list-disc ml-5 space-y-1">'.$rows.'</ul>');
    }

    public function saveResolution(): void
    {
        $state = $this->form->getState();
        $config = $this->config()->refresh();
        $company = auth()->user()->company;

        if (! $config->certificate_uploaded || ! $config->api_token) {
            $this->errorNotif('Primero sube el certificado', 'Completa el tab "Certificado".');
            return;
        }

        $payload = [
            'type_document_id' => (int) $state['res_document_type_id'],
            'prefix' => $state['res_prefix'] ?: null,
            'resolution' => $state['res_resolution_number'] ?: null,
            'resolution_date' => $state['res_resolution_date'] ?: null,
            'technical_key' => $state['res_technical_key'] ?: null,
            'from' => (int) $state['res_range_from'],
            'to' => (int) $state['res_range_to'],
            'date_from' => $state['res_date_from'] ?: null,
            'date_to' => $state['res_date_to'] ?: null,
        ];

        $result = (new DianApiClient($config))->saveResolution($payload);

        if (! $result['ok']) {
            $this->errorNotif('No fue posible guardar la resolución en DIAN', $result['error'] ?? 'Error desconocido');
            return;
        }

        Resolution::updateOrCreate(
            [
                'company_id' => $company->id,
                'document_type_id' => $payload['type_document_id'],
                'prefix' => $payload['prefix'],
            ],
            [
                'document_type_name' => Resolution::DOCUMENT_TYPES[$payload['type_document_id']] ?? 'Documento',
                'resolution_number' => $payload['resolution'],
                'resolution_date' => $payload['resolution_date'],
                'technical_key' => $payload['technical_key'],
                'range_from' => $payload['from'],
                'range_to' => $payload['to'],
                'date_from' => $payload['date_from'],
                'date_to' => $payload['date_to'],
                'active' => true,
            ],
        );

        $this->successNotif('Resolución guardada y registrada en DIAN', 'Asígnala a una o varias sedes para empezar a usarla.');

        // Limpiar campos de "nueva resolución" para permitir cargar otra
        $this->form->fill([
            ...$this->data,
            'res_prefix' => null,
            'res_resolution_number' => null,
            'res_resolution_date' => null,
            'res_technical_key' => null,
            'res_range_from' => null,
            'res_range_to' => null,
            'res_date_from' => null,
            'res_date_to' => null,
        ]);
    }

    public function assignToLocation(): void
    {
        $state = $this->form->getState();
        $resolutionId = $state['assign_resolution_id'] ?? null;
        $locationId = $state['assign_location_id'] ?? null;
        $consecutive = $state['assign_current_consecutive'] ?? null;

        if (! $resolutionId || ! $locationId || ! $consecutive) {
            $this->errorNotif('Completa los 3 campos para asignar');
            return;
        }

        $resolution = Resolution::where('id', $resolutionId)
            ->where('company_id', auth()->user()->company_id)
            ->first();

        if (! $resolution) {
            $this->errorNotif('Resolución no encontrada');
            return;
        }

        $consecutive = (int) $consecutive;
        if ($consecutive < $resolution->range_from || $consecutive > $resolution->range_to) {
            $this->errorNotif(
                'Consecutivo fuera de rango',
                "Debe estar entre {$resolution->range_from} y {$resolution->range_to}.",
            );
            return;
        }

        LocationResolution::updateOrCreate(
            [
                'location_id' => $locationId,
                'dian_resolution_id' => $resolutionId,
            ],
            [
                'current_consecutive' => $consecutive,
                'active' => true,
            ],
        );

        $this->successNotif('Resolución asignada a la sede', "Próxima factura: número {$consecutive}.");
    }

    // ================================================================
    // TAB 5 — Pruebas y ambiente
    // ================================================================

    protected function environmentTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Ambiente actual')
                ->description('Pruebas mientras el set de habilitación no está aprobado por DIAN. Una vez aprobado, cambia a Producción.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('environment')
                        ->label('Ambiente')
                        ->options(CompanyConfig::ENVIRONMENTS)
                        ->required()
                        ->native(false),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('changeEnvironment')
                            ->label('Aplicar cambio en DIAN')
                            ->icon('heroicon-o-arrow-path')
                            ->action('changeEnvironment'),
                    ]),
                ]),

            Forms\Components\Section::make('Set de pruebas DIAN')
                ->description('El TestSetId lo entrega DIAN cuando solicitas la habilitación. Envía la factura de muestra para procesar el set.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('test_set_id')
                        ->label('TestSetId')
                        ->placeholder('e.g. 9c5c1d75-...')
                        ->maxLength(100)
                        ->columnSpan(2),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('sendTestInvoice')
                            ->label('Enviar factura de prueba')
                            ->icon('heroicon-o-paper-airplane')
                            ->color('warning')
                            ->action('sendTestInvoice'),
                    ]),
                ]),
        ];
    }

    public function changeEnvironment(): void
    {
        $state = $this->form->getState();
        $config = $this->config()->refresh();

        if (! $config->api_token) {
            $this->errorNotif('No hay token DIAN', 'Completa primero "Datos de la empresa".');
            return;
        }

        $env = (int) $state['environment'];

        $result = (new DianApiClient($config))->changeEnvironment($env);

        if ($result['ok']) {
            $config->update(['environment' => $env]);
            $this->successNotif(
                'Ambiente actualizado en DIAN',
                'Ahora la empresa opera en '.(CompanyConfig::ENVIRONMENTS[$env] ?? '?').'.',
            );
        } else {
            $this->errorNotif('No fue posible cambiar el ambiente en DIAN', $result['error'] ?? 'Error desconocido');
        }
    }

    public function sendTestInvoice(): void
    {
        $state = $this->form->getState();
        $config = $this->config()->refresh();

        if (! $config->api_token) {
            $this->errorNotif('No hay token DIAN');
            return;
        }
        if (! ($state['test_set_id'] ?? null)) {
            $this->errorNotif('Ingresa el TestSetId que te entregó DIAN');
            return;
        }

        $payload = $this->buildTestInvoicePayload();

        $result = (new DianApiClient($config))->sendTestInvoice($payload, $state['test_set_id']);

        if ($result['ok']) {
            $this->successNotif('Factura de prueba enviada a DIAN', 'Revisa el estado del set en el portal MUISCA.');
        } else {
            $this->errorNotif('Falló el envío de la factura de prueba', $result['error'] ?? 'Error desconocido');
        }
    }

    /**
     * Payload de muestra para el set de habilitación DIAN.
     * Mantiene los valores del controller original — DIAN espera específicamente
     * estos números/textos durante el proceso de habilitación.
     */
    protected function buildTestInvoicePayload(): array
    {
        $today = now()->format('Y-m-d');
        $time = now()->format('H:i:s');

        return [
            'number' => 990000113,
            'type_document_id' => 1,
            'date' => $today,
            'time' => $time,
            'resolution_number' => '18760000001',
            'prefix' => 'SETP',
            'notes' => str_repeat('ESTA ES UNA NOTA DE PRUEBA, ', 13),
            'disable_confirmation_text' => true,
            'seze' => '2021-2017',
            'sendmail' => true,
            'head_note' => 'PRUEBA DE TEXTO LIBRE QUE DEBE POSICIONARSE EN EL ENCABEZADO DE PAGINA DE LA REPRESENTACION GRAFICA DE LA FACTURA ELECTRONICA VALIDACION PREVIA DIAN',
            'foot_note' => 'PRUEBA DE TEXTO LIBRE QUE DEBE POSICIONARSE EN EL PIE DE PAGINA DE LA REPRESENTACION GRAFICA DE LA FACTURA ELECTRONICA VALIDACION PREVIA DIAN',
            'customer' => [
                'identification_number' => 900642123,
                'dv' => 7,
                'name' => 'TECMAX SAS',
                'phone' => '5309441',
                'address' => 'CR 15 78 33 LC 2 255',
                'email' => 'desarrollotecmax@gmail.com',
                'merchant_registration' => '0000000-00',
                'type_document_identification_id' => 6,
                'type_organization_id' => 1,
                'municipality_id' => 149,
                'type_regime_id' => 1,
            ],
            'payment_form' => [
                'payment_form_id' => 1,
                'payment_method_id' => 10,
                'payment_due_date' => $today,
                'duration_measure' => '0',
            ],
            'legal_monetary_totals' => [
                'line_extension_amount' => '769500.00',
                'tax_exclusive_amount' => '950000.00',
                'tax_inclusive_amount' => '950000.00',
                'allowance_total_amount' => '0.00',
                'payable_amount' => '950000.00',
            ],
            'tax_totals' => [
                [
                    'tax_id' => 1,
                    'tax_amount' => '180500',
                    'percent' => '19',
                    'taxable_amount' => '950000.00',
                ],
            ],
            'invoice_lines' => [
                [
                    'unit_measure_id' => 70,
                    'invoiced_quantity' => '1',
                    'line_extension_amount' => '769500.00',
                    'free_of_charge_indicator' => false,
                    'allowance_charges' => [
                        [
                            'charge_indicator' => false,
                            'allowance_charge_reason' => 'DESCUENTO GENERAL',
                            'amount' => '50000.00',
                            'base_amount' => '1000000.00',
                        ],
                    ],
                    'tax_totals' => [
                        [
                            'tax_id' => 1,
                            'tax_amount' => '180500',
                            'taxable_amount' => '950000',
                            'percent' => '19.00',
                        ],
                    ],
                    'description' => 'COMISION POR SERVICIOS',
                    'notes' => 'ESTA ES UNA PRUEBA DE NOTA DE DETALLE DE LINEA.',
                    'code' => 'COMISION',
                    'type_item_identification_id' => 4,
                    'price_amount' => '1000000.00',
                    'base_quantity' => '1',
                ],
            ],
        ];
    }

    // ================================================================
    // Helpers
    // ================================================================

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

    protected function successNotif(string $title, ?string $body = null): void
    {
        Notification::make()->title($title)->body($body)->success()->send();
    }

    protected function errorNotif(string $title, ?string $body = null): void
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();
    }
}
