<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\CompanyResource\Pages;
use App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;
use App\Models\Account;
use App\Models\Company;
use App\Models\Tax;
use App\Services\Accounting\PucProvisioner;
use App\Services\Accounting\TaxesProvisioner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Compañías';

    protected static ?string $modelLabel = 'Compañía';

    protected static ?string $pluralModelLabel = 'Compañías';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre comercial')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('legal_name')
                        ->label('Razón social')
                        ->maxLength(255),

                    Forms\Components\Select::make('document_type')
                        ->label('Tipo de documento')
                        ->options([
                            'nit' => 'NIT',
                            'cc' => 'CC',
                            'ce' => 'CE',
                            'pasaporte' => 'Pasaporte',
                            'rut' => 'RUT',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('nit')
                        ->label('Número')
                        ->required()
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('dv')->label('DV')->maxLength(1),

                    Forms\Components\Select::make('organization_type')
                        ->label('Tipo de organización')
                        ->options([
                            'juridica' => 'Persona Jurídica',
                            'natural' => 'Persona Natural',
                        ])
                        ->required(),
                ]),

            Forms\Components\Section::make('Identidad visual')
                ->description('Logo de la empresa — aparece en tickets POS, facturas y menú público cuando no se haya cargado uno específico.')
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')
                        ->label('Logo de la empresa')
                        ->image()
                        ->imageEditor()
                        ->directory('companies/logos')
                        ->visibility('public')
                        ->maxSize(2048)
                        ->helperText('PNG o JPG, máximo 2 MB. Recomendado fondo transparente y proporción horizontal (ej. 600×200 px).'),
                ]),

            Forms\Components\Section::make('Configuración fiscal y operativa')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('regime_type')
                        ->label('Régimen')
                        ->options([
                            'comun' => 'Responsable de IVA',
                            'no_responsable_iva' => 'No Responsable de IVA',
                            'gran_contribuyente' => 'Gran Contribuyente',
                            'simplificado' => 'Simplificado',
                        ])
                        ->required(),

                    Forms\Components\Select::make('accounting_method')
                        ->label('Contabilidad')
                        ->options([
                            'niif_pymes' => 'NIIF Pymes',
                            'niif_full' => 'NIIF Full',
                        ])
                        ->required(),

                    Forms\Components\Select::make('inventory_method')
                        ->label('Inventario')
                        ->options([
                            'weighted_average' => 'Promedio Ponderado',
                            'fifo' => 'FIFO',
                            'lifo' => 'LIFO',
                        ])
                        ->required(),

                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD'])
                        ->required(),
                ]),

            Forms\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('email')->email(),
                    Forms\Components\TextInput::make('phone')->tel(),
                    Forms\Components\TextInput::make('address')->columnSpanFull(),
                    Forms\Components\TextInput::make('city'),
                    Forms\Components\TextInput::make('department'),
                ]),

            Forms\Components\Section::make('Estado')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('active')->label('Activa'),
                ]),

            Forms\Components\Section::make('Módulos activos')
                ->description('Habilita capacidades verticales de esta empresa. Las requeridas por el plan se activan solas. Apagar Contabilidad solo OCULTA lo contable: los asientos se siguen generando, así que al activarla la empresa ve sus libros completos desde el primer día.')
                ->schema([
                    Forms\Components\CheckboxList::make('active_modules')
                        ->label('Módulos opcionales')
                        ->options([
                            'accounting' => 'Contabilidad (plan de cuentas, asientos, libros y estados financieros)',
                            'restaurant' => 'Restaurante (mesas, comandas, cocina, delivery)',
                            'parking' => 'Parqueadero (tarifas, espacios, mensualidades, incidentes)',
                            'order_taking' => 'Toma pedidos B2B (listas de precios, pedidos, despachos, cartera)',
                            'pharmacy' => 'Farmacia (próximamente)',
                            'retail' => 'Retail / Tiendas (próximamente)',
                            'services' => 'Servicios profesionales (próximamente)',
                            'ecommerce' => 'E-commerce (próximamente)',
                            'payroll' => 'Nómina (próximamente)',
                            'assets' => 'Activos fijos avanzado (próximamente)',
                            'multi_currency' => 'Multi-moneda',
                        ])
                        ->columns(2)
                        ->bulkToggleable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Las ocultas se caen de este listado pero NO de getEloquentQuery(),
            // asi que su ficha sigue abriendose por URL directa.
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleInAdmin())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Company $record) => strtoupper($record->document_type).' '.$record->fullNit()),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->searchable(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('activeSubscription.plan.name')
                    ->label('Plan actual')
                    ->badge()
                    ->placeholder('Sin suscripción'),

                Tables\Columns\TextColumn::make('activeSubscription.status')
                    ->label('Estado sub.')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'trial' => 'warning',
                        'active' => 'success',
                        'past_due' => 'warning',
                        'cancelled', 'expired' => 'gray',
                        'suspended' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('activeSubscription.ends_at')
                    ->label('Vence')
                    ->date('Y-m-d')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrada')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa'),
                Tables\Filters\Filter::make('without_subscription')
                    ->label('Sin suscripción activa')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('activeSubscription')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('provisionPuc')
                    ->label('Provisionar PUC')
                    ->icon('heroicon-o-list-bullet')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Provisionar Plan Único de Cuentas')
                    ->modalDescription(fn (Company $record) => sprintf(
                        'La empresa "%s" tiene actualmente %d cuentas. Esto añadirá el PUC estándar Colombia (~280 cuentas) sin duplicar las que ya existan.',
                        $record->name,
                        Account::withoutGlobalScopes()->where('company_id', $record->id)->count()
                    ))
                    ->modalSubmitActionLabel('Provisionar')
                    ->action(function (Company $record) {
                        $created = app(PucProvisioner::class)->provision($record);

                        Notification::make()
                            ->success()
                            ->title('PUC provisionado')
                            ->body("Se crearon {$created} cuentas nuevas en {$record->name}.")
                            ->send();
                    }),

                Tables\Actions\Action::make('provisionTaxes')
                    ->label('Provisionar Impuestos')
                    ->icon('heroicon-o-receipt-percent')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Provisionar Impuestos Colombia')
                    ->modalDescription(fn (Company $record) => sprintf(
                        'La empresa "%s" tiene actualmente %d impuestos. Esto añadirá el catálogo estándar (IVA 19/5/0, INC, Retefuente, ReteIVA, ReteICA — ~13 impuestos) sin duplicar.',
                        $record->name,
                        Tax::withoutGlobalScopes()->where('company_id', $record->id)->count()
                    ))
                    ->modalSubmitActionLabel('Provisionar')
                    ->action(function (Company $record) {
                        $created = app(TaxesProvisioner::class)->provision($record);

                        Notification::make()
                            ->success()
                            ->title('Impuestos provisionados')
                            ->body("Se crearon {$created} impuestos nuevos en {$record->name}.")
                            ->send();
                    }),

                Tables\Actions\ActionGroup::make([
                    self::resetDataAction(withProducts: false),
                    self::resetDataAction(withProducts: true),
                ])
                    ->label('Resetear datos')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->button(),
            ]);
    }

    /**
     * Acción destructiva: borra los datos transaccionales de la empresa.
     * - $withProducts=false  → conserva products y product_locations (stock queda en 0 al borrar inventory_movements).
     * - $withProducts=true   → borra TODO incluyendo productos.
     *
     * Doble salvaguarda: el usuario debe escribir el NIT EXACTO en un input
     * antes de poder confirmar (anti error humano). Solo super-admin puede
     * ver/ejecutar la acción.
     */
    protected static function resetDataAction(bool $withProducts): Tables\Actions\Action
    {
        $modeKey = $withProducts ? 'full' : 'preserve';
        $modeLabel = $withProducts
            ? 'Borrar TODO (incluso productos)'
            : 'Borrar datos (mantener productos)';

        return Tables\Actions\Action::make('reset_'.$modeKey)
            ->label($modeLabel)
            ->icon($withProducts ? 'heroicon-o-trash' : 'heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->visible(fn () => (bool) auth()->user()?->is_super_admin)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalHeading($modeLabel)
            ->modalWidth('xl')
            ->modalDescription(function (Company $record) use ($withProducts) {
                $preview = app(\App\Services\Maintenance\CompanyDataReset::class)
                    ->preview($record, withProducts: $withProducts);
                $total = array_sum($preview);

                $msg = sprintf(
                    'Vas a borrar todos los datos transaccionales de "%s" (NIT %s). ',
                    $record->name,
                    $record->nit,
                );
                $msg .= $withProducts
                    ? 'Esto incluye los PRODUCTOS y todo su histórico. '
                    : 'Los productos se mantienen pero su inventario queda en 0. ';
                $msg .= 'NO se borran usuarios, clientes, proveedores, sedes, PUC, impuestos, plantillas ni catálogos del restaurante. ';
                $msg .= 'La operación es IRREVERSIBLE.';

                if ($total === 0) {
                    $msg .= "\n\nLa empresa no tiene datos transaccionales — no hay nada para borrar.";
                } else {
                    $msg .= "\n\nSe borrarán {$total} registros distribuidos así:";
                    arsort($preview);
                    $top = array_slice($preview, 0, 8, true);
                    foreach ($top as $table => $count) {
                        $msg .= "\n  · {$table}: ".number_format($count);
                    }
                    if (count($preview) > 8) {
                        $msg .= "\n  · ... y ".(count($preview) - 8).' tabla(s) más';
                    }
                }

                return $msg;
            })
            ->form([
                \Filament\Forms\Components\TextInput::make('confirm_nit')
                    ->label('Escribe el NIT de la empresa para confirmar')
                    ->placeholder('NIT exacto sin puntos ni guiones')
                    ->required()
                    ->autocomplete('off')
                    ->helperText('Esta acción no se puede deshacer. Validamos el NIT para evitar borrar la empresa equivocada.'),
            ])
            ->modalSubmitActionLabel('Borrar definitivamente')
            ->action(function (Company $record, array $data) use ($withProducts) {
                if (trim((string) ($data['confirm_nit'] ?? '')) !== (string) $record->nit) {
                    Notification::make()
                        ->danger()
                        ->title('NIT no coincide')
                        ->body('El NIT digitado no coincide con el de la empresa. No se borró nada.')
                        ->persistent()
                        ->send();
                    return;
                }

                try {
                    $service = app(\App\Services\Maintenance\CompanyDataReset::class);
                    $counts = $withProducts
                        ? $service->resetTransactional($record)
                        : $service->resetTransactionalKeepingProducts($record);

                    $total = array_sum($counts);
                    Notification::make()
                        ->success()
                        ->title('Reset completado')
                        ->body(sprintf(
                            'Se borraron %s registros de "%s". %s',
                            number_format($total),
                            $record->name,
                            $withProducts
                                ? 'Productos eliminados.'
                                : 'Productos conservados (inventario en 0).',
                        ))
                        ->persistent()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Error en el reset')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubscriptionsRelationManager::class,
            RelationManagers\UsersRelationManager::class,
            RelationManagers\LocationsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
