<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ThirdPartyResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\ThirdParty;
use App\Models\Tax;
use App\Support\DianDvCalculator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ThirdPartyResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'third_parties.view'; }
    protected static function managePermission(): string { return 'third_parties.manage'; }

    protected static ?string $model = ThirdParty::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Terceros';

    protected static ?string $modelLabel = 'Tercero';

    protected static ?string $pluralModelLabel = 'Terceros';

    protected static ?string $navigationGroup = 'Contactos';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('thirdparty')->tabs([

                Forms\Components\Tabs\Tab::make('Identificación')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\Group::make()->columns(2)->schema([
                            Forms\Components\Select::make('person_type')
                                ->label('Tipo de persona')
                                ->options(ThirdParty::PERSON_TYPES)
                                ->default('juridica')
                                ->required()
                                ->live()
                                ->native(false),

                            Forms\Components\Select::make('document_type')
                                ->label('Tipo de documento')
                                ->options(ThirdParty::DOCUMENT_TYPES)
                                ->default('nit')
                                ->required()
                                ->native(false)
                                ->live(),

                            Forms\Components\TextInput::make('document_number')
                                ->label('Número de documento')
                                ->required()
                                ->maxLength(30)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                    if ($get('document_type') === 'nit' && $state) {
                                        $set('dv', DianDvCalculator::calculate((string) $state));
                                    }
                                })
                                ->unique(
                                    ignoreRecord: true,
                                    modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule
                                        ->where('company_id', auth()->user()->company_id)
                                        ->where('document_type', $get('document_type'))
                                ),

                            Forms\Components\TextInput::make('dv')
                                ->label('DV')
                                ->maxLength(1)
                                ->disabled()
                                ->dehydrated()
                                ->visible(fn (Forms\Get $get) => $get('document_type') === 'nit'),
                        ]),

                        Forms\Components\Group::make()->columns(2)->schema([
                            Forms\Components\TextInput::make('name')
                                ->label(fn (Forms\Get $get) => $get('person_type') === 'natural'
                                    ? 'Nombre completo'
                                    : 'Nombre comercial')
                                ->required()
                                ->maxLength(200)
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('legal_name')
                                ->label('Razón social')
                                ->maxLength(200)
                                ->visible(fn (Forms\Get $get) => $get('person_type') === 'juridica')
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('trade_name')
                                ->label('Nombre comercial alternativo')
                                ->maxLength(200)
                                ->visible(fn (Forms\Get $get) => $get('person_type') === 'juridica')
                                ->columnSpan(2),
                        ]),

                        Forms\Components\Section::make('Datos del individuo (opcional)')
                            ->visible(fn (Forms\Get $get) => $get('person_type') === 'natural')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('first_name')->label('Primer nombre'),
                                Forms\Components\TextInput::make('middle_name')->label('Segundo nombre'),
                                Forms\Components\TextInput::make('last_name')->label('Primer apellido'),
                                Forms\Components\TextInput::make('second_last_name')->label('Segundo apellido'),
                                Forms\Components\DatePicker::make('birth_date')->label('Fecha de nacimiento'),
                            ]),

                        Forms\Components\Section::make('Roles')
                            ->columns(4)
                            ->schema([
                                Forms\Components\Toggle::make('is_customer')->label('Cliente'),
                                Forms\Components\Toggle::make('is_supplier')->label('Proveedor'),
                                Forms\Components\Toggle::make('is_employee')->label('Empleado'),
                                Forms\Components\Toggle::make('is_other')->label('Otro'),
                            ]),

                        Forms\Components\Toggle::make('active')->label('Activo')->default(true),
                    ]),

                Forms\Components\Tabs\Tab::make('Dirección y contacto')
                    ->icon('heroicon-o-map-pin')
                    ->schema([
                        Forms\Components\Group::make()->columns(2)->schema([
                            Forms\Components\TextInput::make('address')->label('Dirección')->columnSpan(2),
                            Forms\Components\TextInput::make('city')->label('Ciudad'),
                            Forms\Components\TextInput::make('department')->label('Departamento'),
                            Forms\Components\Select::make('dian_municipality_id')
                                ->label('Municipio DIAN')
                                ->helperText('Necesario para facturación electrónica.')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => \App\Models\Dian\Municipality::query()
                                    ->where('name', 'ilike', "%{$search}%")
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (\App\Models\Dian\Municipality $m) => [$m->id => $m->fullName()])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => \App\Models\Dian\Municipality::find($value)?->fullName())
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('country')->label('País')->default('CO')->maxLength(2),
                            Forms\Components\TextInput::make('postal_code')->label('Código postal'),
                            Forms\Components\TextInput::make('phone')->label('Teléfono fijo')->tel(),
                            Forms\Components\TextInput::make('mobile')->label('Móvil')->tel(),
                            Forms\Components\TextInput::make('email')->label('Email')->email(),
                            Forms\Components\TextInput::make('website')->label('Sitio web')->url(),
                            Forms\Components\TextInput::make('contact_person')
                                ->label('Persona de contacto')
                                ->visible(fn (Forms\Get $get) => $get('person_type') === 'juridica'),
                            Forms\Components\TextInput::make('contact_phone')
                                ->label('Teléfono del contacto')
                                ->tel()
                                ->visible(fn (Forms\Get $get) => $get('person_type') === 'juridica'),
                        ]),
                    ]),

                Forms\Components\Tabs\Tab::make('Tributario')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Select::make('regime_type')
                            ->label('Régimen IVA')
                            ->options(ThirdParty::REGIME_TYPES)
                            ->default('comun')
                            ->required()
                            ->native(false),

                        Forms\Components\CheckboxList::make('tax_responsibilities')
                            ->label('Responsabilidades tributarias DIAN')
                            ->options(ThirdParty::TAX_RESPONSIBILITIES)
                            ->columns(2)
                            ->helperText('Selecciona todas las que apliquen según el RUT del tercero.'),

                        Forms\Components\Section::make('Agente retenedor')
                            ->columns(3)
                            ->schema([
                                Forms\Components\Toggle::make('is_self_withholder')->label('Autorretenedor'),
                                Forms\Components\Toggle::make('is_iva_withholder')->label('Retenedor IVA'),
                                Forms\Components\Toggle::make('is_ica_withholder')->label('Retenedor ICA'),
                            ]),

                        Forms\Components\Section::make('Retenciones que se le aplican')
                            ->description('Los interruptores de arriba dicen qué clase de retenedor es. Aquí se concreta con qué tarifa: al tomarle un pedido, estas retenciones se aplican solas sobre la base gravable y el vendedor puede ajustarlas antes de guardar.')
                            ->schema([
                                Forms\Components\CheckboxList::make('retentionTaxes')
                                    ->hiddenLabel()
                                    ->relationship(
                                        'retentionTaxes',
                                        'name',
                                        fn (Builder $query) => $query
                                            ->where('is_active', true)
                                            ->whereIn('type', ['income_withholding', 'vat_withholding', 'ica_withholding'])
                                            ->whereIn('applies_to', ['sale', 'both'])
                                            ->orderBy('code')
                                    )
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Tax $record) => "{$record->code} — {$record->name} ({$record->rate}%)"
                                    )
                                    ->columns(2)
                                    ->bulkToggleable()
                                    ->noSearchResultsMessage('No hay retenciones de venta en el catálogo de impuestos.'),
                            ]),
                    ]),

                Forms\Components\Tabs\Tab::make('Cuentas y términos')
                    ->visible(fn () => \App\Support\ModuleGate::active(\App\Support\ModuleGate::ACCOUNTING))
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Group::make()->columns(2)->schema([
                            Forms\Components\Select::make('default_receivable_account_id')
                                ->label('Cuenta por cobrar (default)')
                                ->relationship(
                                    'defaultReceivableAccount',
                                    'name',
                                    fn (Builder $query) => $query
                                        ->where('type', 'activo')
                                        ->where('accepts_movements', true)
                                        ->orderBy('code'),
                                )
                                ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                                ->searchable(['code', 'name'])
                                ->preload()
                                ->placeholder('— sin cuenta default —'),

                            Forms\Components\Select::make('default_payable_account_id')
                                ->label('Cuenta por pagar (default)')
                                ->relationship(
                                    'defaultPayableAccount',
                                    'name',
                                    fn (Builder $query) => $query
                                        ->where('type', 'pasivo')
                                        ->where('accepts_movements', true)
                                        ->orderBy('code'),
                                )
                                ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                                ->searchable(['code', 'name'])
                                ->preload()
                                ->placeholder('— sin cuenta default —'),

                            Forms\Components\Select::make('default_seller_user_id')
                                ->label('Vendedor asignado')
                                ->relationship('defaultSeller', 'name', fn (Builder $query) => $query
                                    ->where('company_id', auth()->user()->company_id))
                                ->placeholder('—'),

                            Forms\Components\TextInput::make('credit_limit')
                                ->label('Cupo de crédito')
                                ->numeric()
                                ->prefix('$')
                                ->default(0),

                            Forms\Components\TextInput::make('credit_days')
                                ->label('Días de crédito')
                                ->numeric()
                                ->minValue(0)
                                ->default(0),

                            Forms\Components\TextInput::make('payment_terms_days')
                                ->label('Días de pago')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->helperText('Aplicable a proveedores.'),
                        ]),
                    ]),

                Forms\Components\Tabs\Tab::make('Notas')
                    ->icon('heroicon-o-pencil-square')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('')
                            ->rows(8)
                            ->placeholder('Información adicional, acuerdos comerciales, observaciones internas...'),
                    ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('document_number')
                    ->label('Documento')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->description(fn (ThirdParty $record) => strtoupper($record->document_type)
                        .(DianDvCalculator::hasValue($record->dv) ? "-{$record->dv}" : '')),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(['name', 'legal_name', 'trade_name'])
                    ->sortable()
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (ThirdParty $record) => $record->legal_name && $record->legal_name !== $record->name
                        ? $record->legal_name
                        : null),

                Tables\Columns\TextColumn::make('roles')
                    ->label('Roles')
                    ->state(fn (ThirdParty $record) => $record->rolesLabel())
                    ->badge()
                    ->separator(','),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->toggleable()
                    ->state(fn (ThirdParty $record) => $record->mobile ?: $record->phone),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('regime_type')
                    ->label('Régimen')
                    ->formatStateUsing(fn (string $state) => ThirdParty::REGIME_TYPES[$state] ?? $state)
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Cupo')
                    ->money('COP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->date('Y-m-d')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_customer')->label('Cliente'),
                Tables\Filters\TernaryFilter::make('is_supplier')->label('Proveedor'),
                Tables\Filters\TernaryFilter::make('is_employee')->label('Empleado'),
                Tables\Filters\SelectFilter::make('person_type')
                    ->label('Tipo de persona')
                    ->options(ThirdParty::PERSON_TYPES),
                Tables\Filters\SelectFilter::make('regime_type')
                    ->label('Régimen IVA')
                    ->options(ThirdParty::REGIME_TYPES),
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['active' => true])),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn ($records) => $records->each->update(['active' => false])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListThirdParties::route('/'),
            'create' => Pages\CreateThirdParty::route('/create'),
            'edit' => Pages\EditThirdParty::route('/{record}/edit'),
        ];
    }
}
