<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\EmployeeResource\Pages;
use App\Filament\App\Resources\EmployeeResource\RelationManagers\ContractsRelationManager;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Employee;
use App\Models\ThirdParty;
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
