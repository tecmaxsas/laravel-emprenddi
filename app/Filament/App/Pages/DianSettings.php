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
use App\Support\DianDvCalculator;

class DianSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Facturación Electrónica DIAN';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?string $title = 'Facturación Electrónica DIAN';

    protected static ?int $navigationSort = 80;

    protected static string $view = 'filament.app.pages.dian-settings';

    public ?array $data = [];

    /**
     * Última respuesta de la API DIAN por tab, para que el usuario vea
     * exactamente lo que devolvió apidian.emprenddi.com despues de cada
     * accion (no solo un mensaje de exito/error).
     *
     * Estructura: ['tab1' => ['ok'=>bool, 'status'=>int, 'data'=>array, 'error'=>?string, 'time'=>string]]
     */
    public array $apiResponses = [];

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
            'contact_email' => auth()->user()->company?->email,
            'contact_phone' => auth()->user()->company?->phone,

            // Tab 2
            'software_id' => $config->software_id,
            'software_pin' => $config->software_pin,

            // Tab 3
            'certificate_password' => $config->certificate_password,
            'certificate_expedition_date' => $config->certificate_expedition_date?->toDateString(),
            'certificate_expiration_date' => $config->certificate_expiration_date?->toDateString(),

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

            // Nómina electrónica — habilitación (software y resoluciones)
            'software_payroll_id' => $config->software_payroll_id,
            'software_payroll_pin' => $config->software_payroll_pin,
            'payroll_test_set_id' => $config->payroll_test_set_id,
            'payroll_res_prefix' => auth()->user()->company?->payroll_prefix ?: 'NI',
            'payroll_res_from' => 1,
            'payroll_res_to' => 99999999,
            'payroll_note_res_prefix' => 'NA',
            'payroll_note_res_from' => 1,
            'payroll_note_res_to' => 99999999,

            // Tab 4 — numeración de nómina electrónica (vive en la empresa,
            // no en dian_resolutions: la nómina no lleva resolución DIAN).
            'payroll_prefix' => auth()->user()->company?->payroll_prefix,
            'payroll_next_consecutive' => auth()->user()->company?->payroll_next_consecutive ?: 1,

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

                        Forms\Components\Tabs\Tab::make('Nómina electrónica')
                            ->icon('heroicon-o-users')
                            ->schema($this->payrollTabSchema()),

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

            Forms\Components\Section::make('Contacto')
                ->description('DIAN exige correo y teléfono de contacto. Si los cambias acá, se actualizan también en los datos de la empresa.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('contact_email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required()
                        ->maxLength(150)
                        ->placeholder('contacto@miempresa.co'),

                    Forms\Components\TextInput::make('contact_phone')
                        ->label('Teléfono')
                        ->tel()
                        ->required()
                        ->maxLength(30)
                        ->placeholder('6011234567'),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveTab1')
                    ->label('Guardar y registrar en DIAN')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->action('saveTab1'),
            ])->alignEnd(),

            Forms\Components\Placeholder::make('tab1_api_response')
                ->label('')
                ->content(fn () => $this->apiResponseBlock('tab1')),
        ];
    }

    public function saveTab1(): void
    {
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
        $company = auth()->user()->company;

        if (! $company) {
            $this->errorNotif('No hay empresa asociada al usuario');
            return;
        }
        if (! $company->nit || ! DianDvCalculator::hasValue($company->dv)) {
            $this->errorNotif('La empresa debe tener NIT y dígito de verificación', 'Configura primero los datos básicos de la empresa.');
            return;
        }

        // Validacion local del tab 1
        $required = [
            'dian_document_type_id' => 'Tipo de documento',
            'dian_organization_type_id' => 'Tipo de organización',
            'dian_regime_type_id' => 'Régimen',
            'dian_tax_liability_id' => 'Responsabilidad tributaria',
            'dian_municipality_id' => 'Municipio',
            'contact_email' => 'Correo electrónico',
            'contact_phone' => 'Teléfono',
        ];
        foreach ($required as $key => $label) {
            if (empty($state[$key] ?? null)) {
                $this->errorNotif('Falta diligenciar', "Completa el campo \"{$label}\" antes de enviar a DIAN.");
                return;
            }
        }
        if (! filter_var($state['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $this->errorNotif('Correo inválido', 'Verifica el formato del correo de contacto.');
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

        $contactEmail = trim((string) $state['contact_email']);
        $contactPhone = trim((string) $state['contact_phone']);

        $payload = [
            'type_document_identification_id' => $config->dian_document_type_id,
            'type_organization_id' => $config->dian_organization_type_id,
            'type_regime_id' => $config->dian_regime_type_id,
            'type_liability_id' => $config->dian_tax_liability_id,
            'business_name' => $company->legal_name ?: $company->name,
            'merchant_registration' => $config->merchant_registration ?: '0000000-00',
            'municipality_id' => $config->dian_municipality_id,
            'address' => $company->address ?: 'Sin dirección',
            'phone' => (int) preg_replace('/\D/', '', $contactPhone),
            'email' => $contactEmail,
        ];

        $result = (new DianApiClient($config))->registerCompany($company->nit, $company->dv, $payload);
        $this->recordApiResponse('tab1', $result);

        if ($result['ok']) {
            $token = $this->extractToken($result['data']);
            $config->update([
                'company_registered' => true,
                'api_token' => $token ?: $config->api_token,
            ]);
            // Sincroniza correo y telefono que se enviaron a DIAN con los
            // datos basicos de la empresa para que coincidan en facturas,
            // tickets, etc.
            $company->update([
                'email' => $contactEmail,
                'phone' => $contactPhone,
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

            Forms\Components\Placeholder::make('tab2_api_response')
                ->label('')
                ->content(fn () => $this->apiResponseBlock('tab2')),
        ];
    }

    /**
     * Habilitacion de nomina electronica.
     *
     * Es un tramite APARTE del de facturacion: software propio, resoluciones
     * propias (tipo 9 nomina y tipo 10 nota de ajuste) y su propio set de
     * pruebas, aunque la empresa ya facture electronicamente.
     */
    protected function payrollTabSchema(): array
    {
        $config = $this->config();

        return [
            Forms\Components\Section::make('Paso 1 — Software de nómina')
                ->description('La DIAN entrega un identificador y un PIN distintos a los de facturación. Salen del portal, en la habilitación de nómina electrónica.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('software_payroll_id')
                        ->label('ID del software de nómina')
                        ->placeholder('0162cc7e-68f9-4145-a227-0957b4f75e19')
                        ->helperText('El "Identificación" que muestra el portal de la DIAN.'),

                    Forms\Components\TextInput::make('software_payroll_pin')
                        ->label('PIN')
                        ->placeholder('12345'),

                    Forms\Components\Placeholder::make('payroll_software_state')
                        ->label('Estado')
                        ->content(fn () => $config->payroll_software_configured
                            ? '✓ Software de nómina registrado en apidian'
                            : 'Todavía no se ha registrado')
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('savePayrollSoftware')
                            ->label('Registrar software de nómina')
                            ->icon('heroicon-o-cpu-chip')
                            ->action('savePayrollSoftware'),
                    ])->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Paso 2 — Rangos de numeración')
                ->description('La nómina electrónica no lleva resolución autorizada como la facturación: el rango lo define la empresa y se registra en apidian. Son dos: la nómina y la nota de ajuste.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('payroll_res_prefix')
                        ->label('Prefijo nómina')->placeholder('NI'),
                    Forms\Components\TextInput::make('payroll_res_from')
                        ->label('Desde')->numeric()->default(1),
                    Forms\Components\TextInput::make('payroll_res_to')
                        ->label('Hasta')->numeric()->default(99999999),

                    Forms\Components\TextInput::make('payroll_note_res_prefix')
                        ->label('Prefijo nota de ajuste')->placeholder('NA'),
                    Forms\Components\TextInput::make('payroll_note_res_from')
                        ->label('Desde')->numeric()->default(1),
                    Forms\Components\TextInput::make('payroll_note_res_to')
                        ->label('Hasta')->numeric()->default(99999999),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('savePayrollResolutions')
                            ->label('Registrar los dos rangos')
                            ->icon('heroicon-o-hashtag')
                            ->action('savePayrollResolutions'),
                    ])->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Paso 3 — Set de pruebas')
                ->description('La DIAN pide 10 nóminas y 10 notas de ajuste, de las que deben aceptarse 4 y 4. El TestSetId lo entrega el portal.')
                ->schema([
                    Forms\Components\TextInput::make('payroll_test_set_id')
                        ->label('TestSetId de nómina')
                        ->placeholder('4177964d-de81-4178-9d66-bb2fc05d9d92')
                        ->helperText('Mientras esté puesto, los envíos van al set de pruebas. Déjalo vacío para enviar en producción.'),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('savePayrollTestSet')
                            ->label('Guardar TestSetId')
                            ->icon('heroicon-o-beaker')
                            ->action('savePayrollTestSet'),
                    ]),
                ]),
        ];
    }

    /** Registra en apidian el software de nomina que entrega la DIAN. */
    public function savePayrollSoftware(): void
    {
        $config = $this->config();

        $id = trim((string) ($this->data['software_payroll_id'] ?? ''));
        $pin = trim((string) ($this->data['software_payroll_pin'] ?? ''));

        if ($id === '' || $pin === '') {
            $this->errorNotif('Faltan el ID y el PIN del software de nómina');

            return;
        }

        $resultado = (new DianApiClient($config))->saveSoftwarePayroll($id, $pin);

        if (! $resultado['ok']) {
            $this->errorNotif('apidian rechazó el registro', $resultado['error'] ?? 'Sin detalle');

            return;
        }

        $config->update([
            'software_payroll_id' => $id,
            'software_payroll_pin' => $pin,
            'payroll_software_configured' => true,
        ]);

        Notification::make()->success()
            ->title('Software de nómina registrado')
            ->send();
    }

    /**
     * Registra los dos rangos: nomina (tipo 9) y nota de ajuste (tipo 10).
     *
     * Van juntos porque el set de pruebas de la DIAN exige documentos de los
     * dos tipos: registrar solo uno deja la habilitacion a medias.
     */
    public function savePayrollResolutions(): void
    {
        $config = $this->config();
        $client = new DianApiClient($config);

        $rangos = [
            ['tipo' => 9, 'etiqueta' => 'nómina', 'prefijo' => 'payroll_res_prefix', 'desde' => 'payroll_res_from', 'hasta' => 'payroll_res_to'],
            ['tipo' => 10, 'etiqueta' => 'nota de ajuste', 'prefijo' => 'payroll_note_res_prefix', 'desde' => 'payroll_note_res_from', 'hasta' => 'payroll_note_res_to'],
        ];

        $hechos = [];

        foreach ($rangos as $rango) {
            $prefijo = strtoupper(trim((string) ($this->data[$rango['prefijo']] ?? '')));

            if ($prefijo === '') {
                $this->errorNotif('Falta el prefijo de '.$rango['etiqueta']);

                return;
            }

            $resultado = $client->saveResolution([
                'type_document_id' => $rango['tipo'],
                'from' => (int) ($this->data[$rango['desde']] ?? 1),
                'to' => (int) ($this->data[$rango['hasta']] ?? 99999999),
                'prefix' => $prefijo,
            ]);

            if (! $resultado['ok']) {
                $this->errorNotif(
                    'apidian rechazó el rango de '.$rango['etiqueta'],
                    $resultado['error'] ?? 'Sin detalle'
                );

                return;
            }

            $hechos[] = $rango['etiqueta'].' '.$prefijo;
        }

        // El prefijo de nomina tambien se guarda en la empresa: es el que usa
        // el envio para numerar cada desprendible.
        auth()->user()->company?->update([
            'payroll_prefix' => strtoupper(trim((string) $this->data['payroll_res_prefix'])),
        ]);

        Notification::make()->success()
            ->title('Rangos registrados')
            ->body(implode(' · ', $hechos))
            ->send();
    }

    public function savePayrollTestSet(): void
    {
        $this->config()->update([
            'payroll_test_set_id' => trim((string) ($this->data['payroll_test_set_id'] ?? '')) ?: null,
        ]);

        Notification::make()->success()
            ->title('TestSetId guardado')
            ->body($this->data['payroll_test_set_id']
                ? 'Los envíos de nómina irán al set de pruebas.'
                : 'Los envíos de nómina irán a producción.')
            ->send();
    }

    /**
     * Guarda el prefijo y el consecutivo de nomina electronica.
     *
     * Van en la empresa y no en dian_resolutions porque la nomina no lleva
     * resolucion de la DIAN: el rango lo define el empleador.
     */
    public function savePayrollNumbering(): void
    {
        $empresa = auth()->user()->company;

        if (! $empresa) {
            $this->errorNotif('No hay empresa asociada al usuario');

            return;
        }

        $prefijo = trim((string) ($this->data['payroll_prefix'] ?? ''));
        $consecutivo = (int) ($this->data['payroll_next_consecutive'] ?? 1);

        if ($prefijo === '') {
            $this->errorNotif('El prefijo de nómina es obligatorio', 'Sin él no se puede numerar el documento.');

            return;
        }

        if ($consecutivo < 1) {
            $this->errorNotif('El consecutivo debe ser mayor a 0');

            return;
        }

        $empresa->update([
            'payroll_prefix' => strtoupper($prefijo),
            'payroll_next_consecutive' => $consecutivo,
        ]);

        Notification::make()
            ->success()
            ->title('Numeración de nómina guardada')
            ->body("Las nóminas se numerarán {$empresa->payroll_prefix}-{$consecutivo} en adelante.")
            ->send();
    }

    public function saveTab2(): void
    {
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
        if (empty($state['software_id'] ?? null) || empty($state['software_pin'] ?? null)) {
            $this->errorNotif('Falta diligenciar', 'Completa ID Software y PIN antes de enviar a DIAN.');
            return;
        }
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
        $this->recordApiResponse('tab2', $result);

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

                    Forms\Components\DatePicker::make('certificate_expedition_date')
                        ->label('Fecha de expedición')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Cuándo emitió la entidad certificadora el .p12. Solo informativo — no se envía a DIAN.'),

                    Forms\Components\DatePicker::make('certificate_expiration_date')
                        ->label('Fecha de vencimiento')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live()
                        ->helperText('Cuándo expira el certificado. Sirve para alertarte antes del vencimiento.')
                        ->after('certificate_expedition_date'),

                    Forms\Components\Placeholder::make('expiration_status')
                        ->label('')
                        ->columnSpan(2)
                        ->content(function () {
                            $config = $this->config();
                            $days = $config->daysToCertificateExpiration();
                            if ($days === null) return new HtmlString('<div style="padding:8px 12px; background:#f1f5f9; color:#475569; border-radius:6px; font-size:12.5px;">⚠ No has registrado la fecha de vencimiento.</div>');
                            if ($days < 0) {
                                return new HtmlString('<div style="padding:8px 12px; background:#fee2e2; color:#991b1b; border-radius:6px; font-size:13px; font-weight:600;">🚨 El certificado venció hace '.abs($days).' día(s). Renuévalo y vuelve a subirlo.</div>');
                            }
                            if ($days <= 30) {
                                return new HtmlString('<div style="padding:8px 12px; background:#fef3c7; color:#92400e; border-radius:6px; font-size:13px; font-weight:600;">⚠ El certificado vence en '.$days.' día(s). Empieza la renovación pronto.</div>');
                            }
                            return new HtmlString('<div style="padding:8px 12px; background:#dcfce7; color:#166534; border-radius:6px; font-size:13px;">✓ Certificado vigente. Vence en '.$days.' día(s).</div>');
                        }),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('saveTab3')
                    ->label('Subir certificado a DIAN')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->action('saveTab3'),
            ])->alignEnd(),

            Forms\Components\Placeholder::make('tab3_api_response')
                ->label('')
                ->content(fn () => $this->apiResponseBlock('tab3')),
        ];
    }

    public function saveTab3(): void
    {
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
        if (empty($state['certificate_password'] ?? null)) {
            $this->errorNotif('Falta la contraseña del certificado');
            return;
        }
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
        $this->recordApiResponse('tab3', $result);

        if ($result['ok']) {
            $config->update([
                'certificate_filename' => $filename,
                'certificate_password' => $state['certificate_password'],
                'certificate_expedition_date' => $state['certificate_expedition_date'] ?? null,
                'certificate_expiration_date' => $state['certificate_expiration_date'] ?? null,
                'certificate_uploaded' => true,
            ]);
            $this->successNotif('Certificado subido a DIAN', 'Continúa con el tab de Resoluciones.');
        } else {
            // Aunque la API falle, guardamos localmente las fechas si las hay
            // para que el usuario pueda gestionar el vencimiento.
            $config->update([
                'certificate_expedition_date' => $state['certificate_expedition_date'] ?? $config->certificate_expedition_date,
                'certificate_expiration_date' => $state['certificate_expiration_date'] ?? $config->certificate_expiration_date,
            ]);
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
                ->description('Resoluciones DIAN cargadas para esta empresa. Puedes consultar el listado que DIAN tiene autorizado para tu software y guardarlas localmente.')
                ->headerActions([
                    Forms\Components\Actions\Action::make('fetchDianResolutions')
                        ->label('Consultar resoluciones DIAN')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('info')
                        ->tooltip('Llama a apidian.emprenddi.com /numbering-range con tu IDSoftware y guarda las resoluciones devueltas.')
                        ->action('fetchDianResolutions'),
                ])
                ->schema([
                    Forms\Components\Placeholder::make('existing_resolutions')
                        ->label('')
                        ->content(fn () => $this->renderResolutionsList()),
                ]),

            Forms\Components\Section::make('Numeración de nómina electrónica')
                ->description('La nómina electrónica NO lleva resolución de la DIAN: el prefijo y el consecutivo los define la empresa. Se usan al reportar cada desprendible.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('payroll_prefix')
                        ->label('Prefijo')
                        ->maxLength(10)
                        ->placeholder('NI')
                        ->helperText('Ej: NI. Se combina con el consecutivo para numerar cada nómina.'),

                    Forms\Components\TextInput::make('payroll_next_consecutive')
                        ->label('Próximo consecutivo')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->helperText('Avanza solo con cada envío. Solo cámbialo si estás migrando de otro sistema y traes una numeración en curso.'),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('savePayrollNumbering')
                            ->label('Guardar numeración de nómina')
                            ->icon('heroicon-o-check')
                            ->action('savePayrollNumbering'),
                    ])->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Nueva resolución')
                ->description('Datos exactos de la resolución que entrega la DIAN al habilitar el rango de numeración.')
                ->headerActions([
                    Forms\Components\Actions\Action::make('loadDianTestData')
                        ->label('Cargar datos de prueba DIAN')
                        ->icon('heroicon-o-beaker')
                        ->color('warning')
                        ->tooltip('Precarga el formulario con los datos del Set de Habilitación estándar de DIAN para Facturación Electrónica.')
                        ->action('loadDianTestResolution'),
                ])
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

            Forms\Components\Placeholder::make('tab4_api_response')
                ->label('')
                ->content(fn () => $this->apiResponseBlock('tab4')),

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
                            $resolution = $state
                                ? Resolution::query()->where('company_id', auth()->user()?->company_id)->find($state)
                                : null;
                            $set('assign_current_consecutive', $resolution?->range_from);
                        }),

                    Forms\Components\Select::make('assign_location_id')
                        ->label('Sede')
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
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

    /**
     * Consulta a apidian.emprenddi.com las resoluciones autorizadas por DIAN
     * para el IDSoftware de la empresa y las guarda localmente (updateOrCreate
     * por company_id + document_type_id + prefix). Tolerante a las variantes
     * de campos que pueda devolver la API (snake_case, PascalCase, alias).
     */
    public function fetchDianResolutions(): void
    {
        $config = $this->config()->refresh();

        if (! $config->software_id || ! $config->api_token) {
            $this->errorNotif(
                'Falta configuración previa',
                'Necesitas tener el Software configurado (tab 2) antes de consultar resoluciones DIAN.',
            );
            return;
        }

        $result = (new DianApiClient($config))->getNumberRanges([
            'IDSoftware' => $config->software_id,
        ]);
        $this->recordApiResponse('tab4', $result);

        if (! $result['ok']) {
            $this->errorNotif(
                'No fue posible consultar resoluciones en DIAN',
                $result['error'] ?? 'Error desconocido',
            );
            return;
        }

        $items = $this->extractResolutionItems($result['data']);
        if (empty($items)) {
            $this->errorNotif(
                'La consulta no devolvió resoluciones',
                'Revisa el JSON de la respuesta debajo. DIAN puede no tener resoluciones autorizadas todavía.',
            );
            return;
        }

        $companyId = auth()->user()->company_id;
        $saved = 0;
        foreach ($items as $item) {
            $payload = $this->normalizeResolutionItem($item);
            if (! $payload['prefix'] || ! $payload['document_type_id']) {
                continue;
            }
            Resolution::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'document_type_id' => $payload['document_type_id'],
                    'prefix' => $payload['prefix'],
                ],
                $payload + [
                    'company_id' => $companyId,
                    'kind' => Resolution::KIND_ELECTRONIC,
                    'active' => true,
                ],
            );
            $saved++;
        }

        $this->successNotif(
            "Se guardaron {$saved} resolución(es) de DIAN",
            'Revisa la lista de "Resoluciones registradas" arriba.',
        );
    }

    /**
     * Recorre la respuesta de la API buscando el array de resoluciones.
     * Ubicaciones planas (ResolutionList, resolutions, data, items) + rutas
     * anidadas de la respuesta SOAP-like de apidian:
     *   Body → GetNumberingRangeResponse → GetNumberingRangeResult →
     *     ResponseList → NumberRangeResponse (objeto O array)
     */
    protected function extractResolutionItems(array $data): array
    {
        // Posibles ubicaciones directas del array
        $candidates = [
            $data['ResolutionList'] ?? null,
            $data['resolutions'] ?? null,
            $data['Resolutions'] ?? null,
            $data['data']['ResolutionList'] ?? null,
            $data['data']['resolutions'] ?? null,
            $data['data'] ?? null,
            $data['items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ! empty($candidate) && $this->looksLikeResolutionList($candidate)) {
                return $candidate;
            }
        }

        // Busqueda recursiva: recorre el arbol completo del JSON buscando
        // claves conocidas de la respuesta SOAP-like de apidian. Tolera
        // wrappers Envelope/data/response que puedan aparecer en distintos
        // deployments.
        $keysToFind = ['NumberRangeResponse', 'ResolutionList', 'ResponseList'];
        foreach ($keysToFind as $key) {
            $found = $this->deepFind($data, $key);
            if (! $found) continue;

            // Si es objeto asociativo (una sola resolucion), envuelve en array
            if ($this->looksLikeResolutionRow($found)) {
                return [$found];
            }
            // Si es lista de resoluciones, devuelve tal cual
            if (is_array($found) && $this->looksLikeResolutionList($found)) {
                return $found;
            }
            // Si es un contenedor con NumberRangeResponse dentro (ResponseList
            // puede ser {NumberRangeResponse: {...}} en vez de directamente el row)
            if (is_array($found) && isset($found['NumberRangeResponse'])) {
                $inner = $found['NumberRangeResponse'];
                if ($this->looksLikeResolutionRow($inner)) return [$inner];
                if (is_array($inner) && $this->looksLikeResolutionList($inner)) return $inner;
            }
        }

        // Caso límite: la API devolvió un solo objeto en la raíz
        if ($this->looksLikeResolutionRow($data)) {
            return [$data];
        }
        return [];
    }

    /**
     * Busqueda recursiva por clave en un array anidado. Devuelve el primer
     * valor encontrado o null. Usado para tolerar wrappers desconocidos
     * (Envelope, data, response, etc.).
     */
    protected function deepFind(array $arr, string $key)
    {
        if (array_key_exists($key, $arr)) {
            return $arr[$key];
        }
        foreach ($arr as $value) {
            if (is_array($value)) {
                $found = $this->deepFind($value, $key);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    protected function looksLikeResolutionList(array $rows): bool
    {
        $first = reset($rows);
        return is_array($first) && $this->looksLikeResolutionRow($first);
    }

    protected function looksLikeResolutionRow(array $row): bool
    {
        return isset($row['Prefix']) || isset($row['prefix'])
            || isset($row['TypeDocumentId']) || isset($row['type_document_id'])
            || isset($row['Resolution']) || isset($row['resolution'])
            || isset($row['ResolutionNumber']) || isset($row['resolution_number'])
            || isset($row['FromNumber']) || isset($row['ToNumber']);
    }

    /**
     * Normaliza un row del API (en cualquier convención de naming) al shape
     * que usa el modelo Resolution.
     */
    protected function normalizeResolutionItem(array $row): array
    {
        $pick = fn (array $keys, $default = null) => collect($keys)
            ->map(fn ($k) => $row[$k] ?? null)
            ->first(fn ($v) => $v !== null && $v !== '') ?? $default;

        $docTypeId = (int) $pick(['type_document_id', 'TypeDocumentId', 'document_type_id', 'DocumentTypeId']);
        $docTypeName = (string) $pick(['type_document_name', 'TypeDocumentName', 'document_type_name'], '');
        if (! $docTypeName && $docTypeId) {
            $docTypeName = Resolution::DOCUMENT_TYPES[$docTypeId] ?? '';
        }

        // Si no vino document_type_id explicito, asumimos Factura Electronica (1)
        // — la consulta getNumberRanges de apidian solo devuelve resoluciones de
        // facturacion sin decorar el tipo. El usuario puede editar despues.
        if (! $docTypeId) {
            $docTypeId = 1;
            $docTypeName = $docTypeName ?: (Resolution::DOCUMENT_TYPES[1] ?? 'Factura Electrónica');
        }

        return [
            'document_type_id' => $docTypeId ?: null,
            'document_type_name' => $docTypeName ?: null,
            'prefix' => (string) ($pick(['prefix', 'Prefix']) ?? ''),
            'resolution_number' => (string) ($pick(['resolution', 'Resolution', 'resolution_number', 'ResolutionNumber']) ?? ''),
            'resolution_date' => $this->parseDateOrNull($pick(['resolution_date', 'ResolutionDate'])),
            'technical_key' => (string) ($pick(['technical_key', 'TechnicalKey']) ?? ''),
            'range_from' => (int) ($pick(['from', 'From', 'range_from', 'FromNumber']) ?? 0),
            'range_to' => (int) ($pick(['to', 'To', 'range_to', 'ToNumber']) ?? 0),
            'date_from' => $this->parseDateOrNull($pick(['date_from', 'DateFrom', 'ValidDateFrom'])),
            'date_to' => $this->parseDateOrNull($pick(['date_to', 'DateTo', 'ValidDateTo'])),
        ];
    }

    protected function parseDateOrNull($value): ?string
    {
        if (! $value) return null;
        try {
            return \Carbon\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Precarga el formulario con los datos exactos del Set de Habilitación
     * estándar que DIAN entrega a todos los proveedores tecnológicos para el
     * proceso de habilitación de Facturación Electrónica. Mismos valores que
     * usa cualquier integrador (SETP / 18760000001 / clave técnica fija /
     * rango 990000000-995000000 / vigencia 19/01/2019 — 19/01/2030).
     */
    public function loadDianTestResolution(): void
    {
        $this->data = array_merge($this->data, [
            'res_document_type_id' => 1,
            'res_prefix' => 'SETP',
            'res_resolution_number' => '18760000001',
            'res_resolution_date' => '2019-01-19',
            'res_technical_key' => 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
            'res_range_from' => 990000000,
            'res_range_to' => 995000000,
            'res_date_from' => '2019-01-19',
            'res_date_to' => '2030-01-19',
        ]);
        $this->form->fill($this->data);

        \Filament\Notifications\Notification::make()
            ->title('Datos de prueba cargados')
            ->body('Revisa los valores y pulsa "Guardar resolución y enviar a DIAN" para registrarlos.')
            ->success()
            ->send();
    }

    public function saveResolution(): void
    {
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
        $required = [
            'res_document_type_id' => 'Tipo de documento',
            'res_resolution_number' => 'Número de resolución',
            'res_range_from' => 'Rango desde',
            'res_range_to' => 'Rango hasta',
        ];
        foreach ($required as $key => $label) {
            if (empty($state[$key] ?? null)) {
                $this->errorNotif('Falta diligenciar', "Completa el campo \"{$label}\" antes de enviar a DIAN.");
                return;
            }
        }
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
        $this->recordApiResponse('tab4', $result);

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
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
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

                    Forms\Components\Placeholder::make('tab5_env_api_response')
                        ->label('')
                        ->columnSpan(2)
                        ->content(fn () => $this->apiResponseBlock('tab5')),
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

                    Forms\Components\Placeholder::make('tab5_test_api_response')
                        ->label('')
                        ->columnSpan(2)
                        ->content(fn () => $this->apiResponseBlock('tab5_test')),
                ]),
        ];
    }

    public function changeEnvironment(): void
    {
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
        $config = $this->config()->refresh();

        if (! $config->api_token) {
            $this->errorNotif('No hay token DIAN', 'Completa primero "Datos de la empresa".');
            return;
        }

        if (! isset($state['environment']) || ! in_array((int) $state['environment'], [1, 2], true)) {
            $this->errorNotif('Selecciona el ambiente', 'Elige Pruebas o Producción antes de aplicar el cambio.');
            return;
        }

        $env = (int) $state['environment'];

        $result = (new DianApiClient($config))->changeEnvironment($env);
        $this->recordApiResponse('tab5', $result);

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
        // Leer raw data sin disparar validacion de TODO el formulario:
        // cada tab valida sus propios campos en su metodo save respectivo.
        $state = $this->data;
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
        $this->recordApiResponse('tab5_test', $result);

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

    /**
     * Guarda la respuesta de apidian.emprenddi.com asociada a un tab para
     * que se muestre en el visor de respuestas (apiResponseBlock).
     */
    protected function recordApiResponse(string $tabKey, array $result): void
    {
        $this->apiResponses[$tabKey] = array_merge($result, [
            'time' => now()->format('d/m/Y H:i:s'),
        ]);
    }

    /**
     * Renderiza la última respuesta de la API DIAN para un tab. Muestra:
     *  - Status HTTP coloreado (verde si ok, rojo si error)
     *  - Timestamp del envío
     *  - JSON completo decodificado (max-height con scroll)
     * Retorna null (cadena vacía) si aún no hay respuesta para ese tab.
     */
    protected function apiResponseBlock(string $tabKey): HtmlString
    {
        $r = $this->apiResponses[$tabKey] ?? null;
        if (! $r) {
            return new HtmlString('');
        }
        $ok = (bool) ($r['ok'] ?? false);
        $status = (int) ($r['status'] ?? 0);
        $time = (string) ($r['time'] ?? '');
        $err = (string) ($r['error'] ?? '');
        $body = json_encode($r['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $color = $ok ? '#16a34a' : '#dc2626';
        $bg = $ok ? '#dcfce7' : '#fee2e2';
        $fg = $ok ? '#166534' : '#991b1b';
        $statusLabel = $ok ? '✓ OK' : '✗ Error';

        $errLine = $err !== ''
            ? '<div style="margin-top:6px; padding:6px 10px; background:#fee2e2; color:#991b1b; border-radius:4px; font-size:12.5px;">'.e($err).'</div>'
            : '';

        return new HtmlString(
            '<div style="border:1px solid '.$color.'; border-radius:8px; overflow:hidden; margin-top:12px;">'
            .'<div style="padding:8px 12px; background:'.$bg.'; color:'.$fg.'; display:flex; justify-content:space-between; align-items:center; font-size:13px; font-weight:600;">'
            .'<span>📡 Respuesta de apidian.emprenddi.com — '.$statusLabel.' · HTTP '.$status.'</span>'
            .'<span style="font-weight:500; opacity:.8;">'.e($time).'</span>'
            .'</div>'
            .$errLine
            .'<pre style="margin:0; padding:10px 12px; background:#0f172a; color:#e2e8f0; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11.5px; line-height:1.45; max-height:320px; overflow:auto; white-space:pre-wrap; word-break:break-word;">'.e($body).'</pre>'
            .'</div>'
        );
    }
}
