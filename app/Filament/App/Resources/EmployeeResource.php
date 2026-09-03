<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\EmployeeResource\Pages;
use App\Filament\App\Resources\EmployeeResource\RelationManagers\ContractsRelationManager;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Dian\Municipality;
use App\Models\Dian\SubTypeWorker;
use App\Models\Dian\TypeWorker;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\ThirdParty;
use App\Services\Dian\PayrollCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'payroll.employees.view'; }
    protected static function managePermission(): string { return 'payroll.employees.manage'; }

    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Empleados';

    protected static ?string $modelLabel = 'Empleado';

    protected static ?string $pluralModelLabel = 'Empleados';

    protected static ?string $navigationGroup = 'Nómina';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('document_type')
                        ->label('Tipo de documento')
                        ->options(ThirdParty::DOCUMENT_TYPES)
                        ->default('cc')
                        ->native(false)
                        ->required(),

                    Forms\Components\TextInput::make('document_number')
                        ->label('Número de documento')
                        ->required()
                        ->maxLength(30)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule) => $rule
                                ->where('company_id', auth()->user()?->company_id),
                        )
                        ->validationMessages(['unique' => 'Ya existe un empleado con ese documento.']),

                    Forms\Components\DatePicker::make('birth_date')
                        ->label('Fecha de nacimiento'),

                    Forms\Components\Select::make('gender')
                        ->label('Sexo')
                        ->options(Employee::GENDERS)
                        ->native(false),

                    Forms\Components\TextInput::make('first_name')
                        ->label('Primer nombre')
                        ->required()
                        ->maxLength(60),

                    Forms\Components\TextInput::make('middle_name')
                        ->label('Otros nombres')
                        ->maxLength(60),

                    Forms\Components\TextInput::make('last_name')
                        ->label('Primer apellido')
                        ->required()
                        ->maxLength(60),

                    Forms\Components\TextInput::make('second_last_name')
                        ->label('Segundo apellido')
                        ->maxLength(60),
                ]),

            Forms\Components\Section::make('Contacto')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('phone')
                        ->label('Teléfono')
                        ->maxLength(40),

                    Forms\Components\TextInput::make('city')
                        ->label('Ciudad')
                        ->maxLength(80),

                    Forms\Components\TextInput::make('address')
                        ->label('Dirección')
                        ->maxLength(255)
                        ->columnSpan(4),
                ]),

            Forms\Components\Section::make('Pago de nómina')
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('payment_method')
                        ->label('Forma de pago')
                        ->options(Employee::PAYMENT_METHODS)
                        ->native(false),

                    Forms\Components\TextInput::make('bank_name')
                        ->label('Banco')
                        ->maxLength(80),

                    Forms\Components\Select::make('bank_account_type')
                        ->label('Tipo de cuenta')
                        ->options(Employee::BANK_ACCOUNT_TYPES)
                        ->native(false),

                    Forms\Components\TextInput::make('bank_account_number')
                        ->label('Número de cuenta')
                        ->maxLength(40),
                ]),

            Forms\Components\Section::make('Seguridad social y parafiscales')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('eps_name')
                        ->label('EPS (salud)')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('pension_fund_name')
                        ->label('Fondo de pensiones (AFP)')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('arl_name')
                        ->label('ARL (riesgos laborales)')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('compensation_fund_name')
                        ->label('Caja de compensación')
                        ->maxLength(100),
                ]),

            Forms\Components\Section::make('Deducciones para retención en la fuente')
                ->description('Deducciones que el trabajador certifica. La liquidación las aplica al calcular la retención (procedimiento 1).')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('housing_interest_deduction')
                        ->label('Intereses de vivienda (mensual)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->helperText('Interés mensual del crédito de vivienda. Tope legal: 100 UVT.'),

                    Forms\Components\TextInput::make('prepaid_health_deduction')
                        ->label('Medicina prepagada (mensual)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->helperText('Pago mensual de medicina prepagada o seguros de salud. Tope: 16 UVT.'),

                    Forms\Components\Toggle::make('has_dependents')
                        ->label('Tiene dependientes a cargo')
                        ->inline(false)
                        ->helperText('Aplica la deducción del 10% del ingreso, con tope de 32 UVT.'),
                ]),

            // Solo al crear. Despues el contrato se administra en su propia
            // pestaña, porque un empleado acumula varios a lo largo del tiempo
            // y hay que conservar el historial.
            Forms\Components\Section::make('Contrato')
                ->description('El contrato inicial. La liquidación sólo toma empleados con contrato vigente, '
                    .'y de aquí salen el salario y el tipo de contrato que se reportan a la DIAN.')
                ->columns(3)
                ->visibleOn('create')
                ->schema([
                    Forms\Components\TextInput::make('contrato.position')
                        ->label('Cargo')
                        ->required()
                        ->maxLength(80),

                    Forms\Components\Select::make('contrato.contract_type')
                        ->label('Tipo de contrato')
                        ->options(EmploymentContract::CONTRACT_TYPES)
                        ->default('indefinido')
                        ->native(false)
                        ->required(),

                    Forms\Components\Select::make('contrato.salary_type')
                        ->label('Tipo de salario')
                        ->options(EmploymentContract::SALARY_TYPES)
                        ->default('ordinario')
                        ->native(false)
                        ->required(),

                    Forms\Components\TextInput::make('contrato.salary')
                        ->label('Salario')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->required(),

                    Forms\Components\Select::make('contrato.payment_frequency')
                        ->label('Frecuencia de pago')
                        ->options(EmploymentContract::PAYMENT_FREQUENCIES)
                        ->default('mensual')
                        ->native(false)
                        ->required(),

                    Forms\Components\Select::make('contrato.risk_class')
                        ->label('Clase de riesgo (ARL)')
                        ->options(EmploymentContract::RISK_CLASSES)
                        ->default('1')
                        ->native(false),

                    Forms\Components\DatePicker::make('contrato.start_date')
                        ->label('Inicio del contrato')
                        ->default(now())
                        ->required(),

                    Forms\Components\DatePicker::make('contrato.end_date')
                        ->label('Fin del contrato')
                        ->helperText('Sólo para término fijo o por obra.'),

                    Forms\Components\Toggle::make('contrato.transport_allowance_applies')
                        ->label('Aplica auxilio de transporte')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Nómina electrónica (DIAN)')
                ->description('Sólo hace falta para reportar la nómina a la DIAN. Los valores por defecto '
                    .'sirven para un empleado normal; cámbialos si este no lo es.')
                ->columns(2)
                ->collapsed()
                ->schema([
                    Forms\Components\Select::make('dian_municipality_id')
                        ->label('Municipio de trabajo')
                        ->placeholder('El mismo de la empresa')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Municipality::query()
                            ->where('name', 'ilike', "%{$search}%")
                            ->orderBy('name')
                            ->limit(40)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Municipality::find($value)?->name)
                        ->helperText('Déjalo vacío si trabaja donde está registrada la empresa.'),

                    Forms\Components\Select::make('payroll_type_worker_id')
                        ->label('Tipo de trabajador')
                        ->options(fn () => TypeWorker::query()->orderBy('id')->pluck('name', 'id')->all())
                        ->default(PayrollCatalog::TYPE_WORKER_DEPENDIENTE)
                        ->searchable()
                        ->native(false)
                        ->helperText('Dependiente es el caso normal. Cámbialo para aprendices del SENA, '
                            .'servicio doméstico o trabajadores de tiempo parcial.'),

                    Forms\Components\Select::make('payroll_sub_type_worker_id')
                        ->label('Subtipo de trabajador')
                        ->options(fn () => SubTypeWorker::query()->orderBy('id')->pluck('name', 'id')->all())
                        ->default(PayrollCatalog::SUB_TYPE_WORKER_NO_APLICA)
                        ->searchable()
                        ->native(false)
                        ->helperText('"No aplica" salvo que sea pensionado activo o esté exceptuado de pensión.'),

                    Forms\Components\Toggle::make('high_risk_pension')
                        ->label('Pensión de alto riesgo')
                        ->helperText('La DIAN usa un concepto de deducción distinto. Marcarlo mal reporta '
                            .'mal el aporte a pensión.'),
                ]),

            Forms\Components\Section::make('Vinculación')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('hire_date')
                        ->label('Fecha de ingreso')
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options(Employee::STATUSES)
                        ->default(Employee::STATUS_ACTIVE)
                        ->native(false)
                        ->required(),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Tercero contable (opcional)')
                        ->placeholder('Sin vincular')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                  ->orWhere('document_number', 'like', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => "{$t->document_number} — {$t->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ThirdParty::find($value)
                            ? ThirdParty::find($value)->document_number.' — '.ThirdParty::find($value)->name
                            : null)
                        ->helperText('Se usará al contabilizar la nómina.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Observaciones')
                        ->rows(2)
                        ->columnSpan(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('activeContract'))
            ->defaultSort('first_name')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Empleado')
                    ->state(fn (Employee $record) => $record->fullName())
                    ->description(fn (Employee $record) => strtoupper((string) $record->document_type).' '.$record->document_number)
                    ->weight('semibold')
                    ->searchable(['first_name', 'last_name', 'document_number']),

                Tables\Columns\TextColumn::make('position')
                    ->label('Cargo')
                    ->state(fn (Employee $record) => $record->activeContract?->position)
                    ->placeholder('Sin contrato activo'),

                Tables\Columns\TextColumn::make('salary')
                    ->label('Salario')
                    ->state(fn (Employee $record) => $record->activeContract?->salary)
                    ->money('COP')
                    ->placeholder('—')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('hire_date')
                    ->label('Ingreso')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => Employee::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => $state === Employee::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Employee::STATUSES)
                    ->default(Employee::STATUS_ACTIVE),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('Sin empleados')
            ->emptyStateDescription('Registrá los empleados y sus contratos para liquidar nómina.');
    }

    public static function getRelations(): array
    {
        return [
            ContractsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
