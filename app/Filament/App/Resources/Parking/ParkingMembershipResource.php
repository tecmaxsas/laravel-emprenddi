<?php

namespace App\Filament\App\Resources\Parking;

use App\Filament\App\Resources\Parking\ParkingMembershipResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingMembership;
use App\Models\Parking\VehicleType;
use App\Models\PaymentMethod;
use App\Models\ThirdParty;
use App\Services\Parking\ParkingBillingEngine;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ParkingMembershipResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'parking.manage'; }
    protected static function managePermission(): string { return 'parking.manage'; }

    protected static ?string $model = ParkingMembership::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationLabel = 'Mensualidades / Convenios';
    protected static ?string $modelLabel = 'Mensualidad';
    protected static ?string $pluralModelLabel = 'Mensualidades';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del convenio')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('kind')
                        ->label('Tipo')->required()->native(false)->live()
                        ->options(ParkingMembership::KINDS)
                        ->default(ParkingMembership::KIND_INDIVIDUAL),

                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->required()->native(false)->searchable()
                        ->options(fn () => ParkingLot::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('vehicle_type_id')
                        ->label('Tipo de vehículo')->native(false)->searchable()
                        ->placeholder('Cualquiera')
                        ->options(fn () => VehicleType::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('sort_order')->pluck('name', 'id')->all()),

                    Forms\Components\TextInput::make('name')->label('Nombre del convenio')
                        ->required()->maxLength(150)
                        ->placeholder('Mensualidad ABC123 · Convenio Acme 2026')
                        ->columnSpan(3),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente / Empresa')
                        ->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                  ->orWhere('document_number', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')->limit(30)->get()
                            ->mapWithKeys(fn ($c) => [$c->id => trim(($c->document_number ?: '—').' · '.$c->name)])
                            ->all())
                        ->getOptionLabelUsing(fn ($v) => ThirdParty::find($v)?->name)
                        // Alta rápida sin salir de la mensualidad: solo lo
                        // mínimo obligatorio del tercero. El resto (dirección,
                        // responsabilidades DIAN, etc.) se completa después
                        // desde Terceros si hace falta facturarle.
                        ->createOptionForm([
                            Forms\Components\Select::make('person_type')
                                ->label('Tipo de persona')->native(false)
                                ->options(ThirdParty::PERSON_TYPES)
                                ->default('natural')->required(),
                            Forms\Components\Select::make('document_type')
                                ->label('Tipo de documento')->native(false)
                                ->options(ThirdParty::DOCUMENT_TYPES)
                                ->default('cc')->required(),
                            Forms\Components\TextInput::make('document_number')
                                ->label('Número de documento')
                                ->required()->maxLength(30),
                            Forms\Components\TextInput::make('name')
                                ->label('Nombre completo / Razón social')
                                ->required()->maxLength(200)->columnSpanFull(),
                            Forms\Components\TextInput::make('mobile')
                                ->label('Celular')->tel()->maxLength(30),
                            Forms\Components\TextInput::make('email')
                                ->label('Correo')->email()->maxLength(150),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            $companyId = auth()->user()?->company_id;

                            // Si ya existe con ese documento, se reutiliza en
                            // vez de crear un duplicado — y se marca como
                            // cliente por si estaba solo como proveedor.
                            $existing = ThirdParty::query()
                                ->where('company_id', $companyId)
                                ->where('document_number', $data['document_number'])
                                ->first();

                            if ($existing) {
                                if (! $existing->is_customer) {
                                    $existing->update(['is_customer' => true]);
                                }

                                return $existing->id;
                            }

                            return ThirdParty::create([
                                ...$data,
                                'company_id' => $companyId,
                                'is_customer' => true,
                            ])->id;
                        })
                        ->createOptionModalHeading('Nuevo cliente')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('amount')
                        ->label('Monto')->numeric()->minValue(0)->prefix('$')->required()
                        ->helperText('Mensual para individual · Total del convenio para corporate.'),
                ]),

            Forms\Components\Section::make('Placas autorizadas')
                ->description(fn (Forms\Get $get) => $get('kind') === ParkingMembership::KIND_CORPORATE
                    ? 'Convenio corporativo: agrega todas las placas que cubre.'
                    : 'Mensualidad individual: una sola placa.')
                ->schema([
                    Forms\Components\Repeater::make('plates')
                        ->label('Placas')
                        ->simple(
                            Forms\Components\TextInput::make('plate')
                                ->placeholder('ABC123')
                                ->required()
                                ->maxLength(20)
                                ->extraInputAttributes(['style' => 'text-transform: uppercase; font-family: ui-monospace, monospace; letter-spacing: 1px;'])
                        )
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(fn (Forms\Get $get) => $get('kind') === ParkingMembership::KIND_INDIVIDUAL ? 1 : null)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Vigencia y estado')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('start_date')
                        ->label('Desde')->required()->native(false)->default(today()),
                    Forms\Components\DatePicker::make('end_date')
                        ->label('Hasta')->required()->native(false)
                        ->default(today()->copy()->addMonth())
                        ->after('start_date'),

                    Forms\Components\Select::make('status')
                        ->label('Estado')->required()->native(false)
                        ->options(ParkingMembership::STATUSES)
                        ->default(ParkingMembership::STATUS_ACTIVE),

                    Forms\Components\Toggle::make('auto_renew')
                        ->label('Auto-renovar al vencer'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('end_date')->columns([
            Tables\Columns\TextColumn::make('name')->label('Convenio')->searchable()->weight('semibold')->limit(40),
            Tables\Columns\TextColumn::make('kind')->label('Tipo')->badge()
                ->formatStateUsing(fn (string $state) => ParkingMembership::KINDS[$state] ?? $state),
            Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->searchable()->limit(30),
            Tables\Columns\TextColumn::make('parkingLot.name')->label('Parqueadero'),
            Tables\Columns\TextColumn::make('plates_count')
                ->label('Placas')
                ->state(fn (ParkingMembership $record) => count($record->plates ?? []))
                ->alignCenter(),
            Tables\Columns\TextColumn::make('end_date')->label('Vence')->date('Y-m-d'),
            Tables\Columns\TextColumn::make('days_left')
                ->label('Días restantes')
                ->state(fn (ParkingMembership $record) => $record->daysToExpiration())
                ->badge()
                ->color(fn ($state) => $state === null ? 'gray' : ($state < 0 ? 'danger' : ($state <= 7 ? 'warning' : 'success')))
                ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state < 0 ? abs($state).' vencida' : $state.' día(s)')),
            Tables\Columns\TextColumn::make('amount')->label('Monto')->money('COP')->alignEnd(),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn (string $state) => ParkingMembership::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    ParkingMembership::STATUS_ACTIVE => 'success',
                    ParkingMembership::STATUS_EXPIRED => 'danger',
                    ParkingMembership::STATUS_CANCELLED => 'gray',
                    default => 'gray',
                }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(ParkingMembership::STATUSES),
            Tables\Filters\SelectFilter::make('kind')->options(ParkingMembership::KINDS),
            Tables\Filters\SelectFilter::make('parking_lot_id')->label('Parqueadero')
                ->options(fn () => ParkingLot::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->orderBy('name')->pluck('name', 'id')->all()),
            Tables\Filters\Filter::make('expiring')
                ->label('Vencen en ≤ 15 días')
                ->query(fn ($query) => $query->expiringWithin(15)),
        ])->actions([
            Tables\Actions\Action::make('billMembership')
                ->label('Cobrar')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (ParkingMembership $record) => $record->status === ParkingMembership::STATUS_ACTIVE
                    && (float) $record->amount > 0)
                ->form([
                    Forms\Components\Section::make('Datos del cobro')
                        ->description(fn (ParkingMembership $record) => "Cliente: {$record->customer?->name} · Monto: $".number_format((float) $record->amount, 0, ',', '.'))
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('payment_method')
                                ->label('Medio de pago')->native(false)->required()
                                ->default('cash')
                                ->options(function () {
                                    $companyId = auth()->user()?->company_id;
                                    $configured = PaymentMethod::query()
                                        ->where('company_id', $companyId)
                                        ->where('active', true)
                                        ->orderBy('name')->pluck('name', 'code')->all();
                                    return ! empty($configured) ? $configured : [
                                        'cash' => 'Efectivo', 'card' => 'Tarjeta',
                                        'transfer' => 'Transferencia', 'credit' => 'A crédito',
                                    ];
                                }),
                            Forms\Components\Select::make('account_id')
                                ->label('Cuenta contable')->native(false)->required()->searchable()
                                ->default(fn () => Account::query()
                                    ->where('company_id', auth()->user()?->company_id)
                                    ->where('accepts_movements', true)->where('active', true)
                                    ->where(function ($q) {
                                        $q->where('code', '110505')->orWhere('code', '1105');
                                    })
                                    ->orderBy('code')->value('id'))
                                ->options(fn () => Account::query()
                                    ->where('company_id', auth()->user()?->company_id)
                                    ->where('accepts_movements', true)->where('active', true)
                                    ->where(function ($q) {
                                        $q->where('code', 'like', '11%')->orWhere('code', 'like', '12%');
                                    })
                                    ->orderBy('code')->limit(50)->get()
                                    ->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name}"])
                                    ->all()),
                            Forms\Components\Select::make('invoice_kind')
                                ->label('Tipo de factura')->native(false)->required()
                                ->options(['pos' => 'POS (no electrónica)', 'electronic' => 'Electrónica DIAN'])
                                ->default('pos'),
                            Forms\Components\DatePicker::make('period_start')
                                ->label('Período desde')->native(false)
                                ->default(fn (ParkingMembership $record) => $record->start_date)
                                ->helperText('Aparece en la descripción de la factura.'),
                            Forms\Components\DatePicker::make('period_end')
                                ->label('Período hasta')->native(false)
                                ->default(fn (ParkingMembership $record) => $record->end_date),
                        ]),
                ])
                ->action(function (ParkingMembership $record, array $data) {
                    try {
                        $invoice = app(ParkingBillingEngine::class)->issueForMembership($record, [
                            'invoice_kind' => $data['invoice_kind'] ?? 'pos',
                            'payment_method' => $data['payment_method'] ?? 'cash',
                            'account_id' => (int) ($data['account_id'] ?? 0),
                            'period_start' => $data['period_start'] ?? null,
                            'period_end' => $data['period_end'] ?? null,
                        ]);
                        Notification::make()
                            ->title('Mensualidad facturada')
                            ->body('Factura '.$invoice->fullNumber().' · $'.number_format((float) $invoice->total, 0, ',', '.'))
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo facturar')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingMemberships::route('/'),
            'create' => Pages\CreateParkingMembership::route('/create'),
            'edit' => Pages\EditParkingMembership::route('/{record}/edit'),
        ];
    }
}
