<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\ReservationGroupResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\MealPlan;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationGroup;
use App\Models\Hotel\RoomType;
use App\Models\ThirdParty;
use App\Services\Hotel\HotelBillingEngine;
use App\Services\Hotel\HotelGroupEngine;
use App\Support\PaymentAccountResolver;
use App\Support\PaymentMethodOptions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Grupos: el matrimonio, el congreso, la empresa.
 *
 * Un grupo no reemplaza a las reservas, las envuelve. Cada habitación conserva
 * la suya —con su check-in y su folio— y lo que el grupo aporta es crearlas de
 * una vez, verlas juntas y **facturarlas en un solo documento**, que es lo que
 * una empresa o una agencia necesita para pagar.
 */
class ReservationGroupResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.reservations.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = ReservationGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Grupos';

    protected static ?string $modelLabel = 'Grupo';

    protected static ?string $pluralModelLabel = 'Grupos';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 22;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('El grupo')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->required()
                        ->native(false)
                        ->live()
                        ->options(fn () => static::hoteles())
                        ->default(fn () => array_key_first(static::hoteles())),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre del grupo')
                        ->required()
                        ->maxLength(150)
                        ->columnSpan(2)
                        ->placeholder('Boda Gómez — Pérez')
                        ->helperText('Es por donde se busca el día de la llegada, cuando alguien dice '
                            .'«vengo con la boda Gómez».'),

                    Forms\Components\DatePicker::make('check_in_date')
                        ->label('Entrada')
                        ->required()
                        ->default(now()),

                    Forms\Components\DatePicker::make('check_out_date')
                        ->label('Salida')
                        ->required()
                        ->default(now()->addDay()),

                    Forms\Components\Select::make('source')
                        ->label('Origen')
                        ->native(false)
                        ->options(Reservation::SOURCES)
                        ->default('agency'),
                ]),

            Forms\Components\Section::make('Habitaciones que se apartan')
                ->description('Si no alcanzan, se crean las que haya y se avisa cuántas faltaron: con el '
                    .'cliente al teléfono es mejor apartar siete y decirlo que no apartar ninguna.')
                ->schema([
                    Forms\Components\Repeater::make('habitaciones')
                        ->label('')
                        ->columns(4)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Agregar otro tipo')
                        ->schema([
                            Forms\Components\Select::make('room_type_id')
                                ->label('Tipo')
                                ->required()
                                ->native(false)
                                ->options(fn (Get $get) => RoomType::query()
                                    ->where('company_id', auth()->user()?->company_id)
                                    ->when($get('../../hotel_id'), fn ($q) => $q->where('hotel_id', $get('../../hotel_id')))
                                    ->where('active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'id')
                                    ->all()),

                            Forms\Components\TextInput::make('cantidad')
                                ->label('Cuántas')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(60)
                                ->required()
                                ->default(1),

                            Forms\Components\TextInput::make('adults')
                                ->label('Adultos c/u')
                                ->numeric()
                                ->minValue(1)
                                ->default(2),

                            Forms\Components\TextInput::make('rate_per_night')
                                ->label('Tarifa pactada')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->helperText('Vacío = la del tipo o la temporada.'),
                        ]),

                    Forms\Components\Select::make('meal_plan_id')
                        ->label('Plan de alimentación')
                        ->native(false)
                        ->placeholder('Sin plan')
                        ->options(fn (Get $get) => MealPlan::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                            ->where('active', true)
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (MealPlan $p) => [$p->id => $p->etiqueta()])
                            ->all())
                        ->helperText('Se aplica a todas las habitaciones del bloque.'),
                ]),

            Forms\Components\Section::make('Quién paga y quién coordina')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente que paga')
                        ->native(false)
                        ->searchable()
                        ->helperText('La empresa o la agencia. Hace falta para facturar el grupo junto '
                            .'y para dejarlo a crédito.')
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where(fn ($q) => $q
                                ->where('name', 'ilike', "%{$search}%")
                                ->orWhere('document_number', 'ilike', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => ($t->document_number ?: '—').' · '.$t->name])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ThirdParty::find($value)?->name),

                    Forms\Components\Toggle::make('bill_together')
                        ->label('Facturar todo junto')
                        ->default(true)
                        ->helperText('Apagado, cada habitación se cobra en su propio check-out. Útil '
                            .'cuando la empresa paga el alojamiento y cada quien sus consumos.'),

                    Forms\Components\TextInput::make('contact_name')->label('Contacto')->maxLength(120),
                    Forms\Components\TextInput::make('contact_phone')->label('Teléfono')->tel()->maxLength(30),
                    Forms\Components\TextInput::make('contact_email')->label('Correo')->email()->maxLength(150),

                    Forms\Components\Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('check_in_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Grupo')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (ReservationGroup $record) => $record->customer?->name),

                Tables\Columns\TextColumn::make('check_in_date')
                    ->label('Entrada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (ReservationGroup $record) => $record->noches().' noche(s)'),

                // Lo que recepción necesita el día de la llegada: cuántos
                // faltan por entrar y cuántos ya están adentro.
                Tables\Columns\TextColumn::make('avance')
                    ->label('Habitaciones')
                    ->state(function (ReservationGroup $record) {
                        $r = $record->resumen();

                        return sprintf('%d · %d alojadas', $r['habitaciones'], $r['alojados']);
                    })
                    ->description(function (ReservationGroup $record) {
                        $r = $record->resumen();

                        return $r['canceladas'] > 0 ? $r['canceladas'].' cancelada(s)' : null;
                    }),

                Tables\Columns\TextColumn::make('estimado')
                    ->label('Estimado')
                    ->state(fn (ReservationGroup $record) => $record->resumen()['estimado'])
                    ->money('COP', 0),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ReservationGroup::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ReservationGroup::STATUS_OPEN => 'success',
                        ReservationGroup::STATUS_CLOSED => 'gray',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('factura')
                    ->label('Factura')
                    ->state(fn (ReservationGroup $record) => $record->invoice?->fullNumber())
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(ReservationGroup::STATUSES),
                Tables\Filters\SelectFilter::make('hotel_id')->label('Hotel')->options(fn () => static::hoteles()),
                Tables\Filters\Filter::make('abiertos')
                    ->label('Solo abiertos')
                    ->query(fn ($query) => $query->abiertos()),
            ])
            ->actions([
                Tables\Actions\Action::make('confirmarTodas')
                    ->label('Confirmar todas')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (ReservationGroup $record) => $record->estaAbierto())
                    ->action(function (ReservationGroup $record) {
                        $confirmadas = app(HotelGroupEngine::class)->confirmar($record);

                        Notification::make()
                            ->title($confirmadas > 0
                                ? $confirmadas.' reserva(s) confirmadas'
                                : 'No había ninguna por confirmar')
                            ->success()->send();
                    }),

                Tables\Actions\Action::make('facturarGrupo')
                    ->label('Facturar el grupo')
                    ->icon('heroicon-o-document-currency-dollar')
                    ->color('primary')
                    ->visible(fn (ReservationGroup $record) => $record->estaAbierto()
                        && $record->bill_together
                        && $record->openStays()->isNotEmpty())
                    ->modalHeading('Facturar todo el grupo en un documento')
                    ->modalDescription(fn (ReservationGroup $record) => 'Se liquidan y cierran las '
                        .$record->openStays()->count().' estadía(s) abiertas, y sale una sola factura.')
                    ->form([
                        Forms\Components\Select::make('invoice_kind')
                            ->label('Documento')
                            ->native(false)
                            ->required()
                            ->default('pos')
                            ->options([
                                'pos' => 'Tiquete POS',
                                'electronic' => 'Factura electrónica (DIAN)',
                            ]),

                        Forms\Components\Toggle::make('a_credito')
                            ->label('Dejar a crédito')
                            ->live()
                            ->helperText('Lo normal con una empresa o una agencia: la factura queda en '
                                .'cartera con el plazo del cliente.'),

                        Forms\Components\Select::make('payment_method')
                            ->label('Forma de pago')
                            ->native(false)
                            ->default('cash')
                            ->visible(fn (Get $get) => ! $get('a_credito'))
                            ->options(fn () => PaymentMethodOptions::para()),
                    ])
                    ->action(function (ReservationGroup $record, array $data) {
                        $metodo = $data['payment_method'] ?? 'cash';

                        try {
                            $factura = app(HotelBillingEngine::class)->issueForGroup($record, [
                                'invoice_kind' => $data['invoice_kind'],
                                'payment_method' => $metodo,
                                'account_id' => PaymentAccountResolver::forMethod($metodo),
                                'a_credito' => (bool) ($data['a_credito'] ?? false),
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo facturar el grupo')
                                ->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        $saldo = round((float) $factura->total - (float) $factura->paid_amount, 2);

                        Notification::make()
                            ->title('Factura '.$factura->fullNumber())
                            ->body('$'.number_format((float) $factura->total, 0, ',', '.')
                                .($saldo > 0.01 ? ' · queda a crédito' : ' · cobrada'))
                            ->success()->persistent($saldo > 0.01)->send();
                    }),

                Tables\Actions\Action::make('cancelarGrupo')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReservationGroup $record) => $record->estaAbierto())
                    ->form([
                        Forms\Components\Textarea::make('motivo')
                            ->label('Motivo')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (ReservationGroup $record, array $data) {
                        try {
                            $r = app(HotelGroupEngine::class)->cancelar($record, (string) $data['motivo']);
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo cancelar')
                                ->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Grupo cancelado')
                            ->body($r['canceladas'].' reserva(s) canceladas'
                                .($r['alojados'] > 0
                                    ? '. OJO: '.$r['alojados'].' habitación(es) tienen gente adentro y no se '
                                        .'tocaron: eso se resuelve con un check-out.'
                                    : '.'))
                            ->warning()->persistent($r['alojados'] > 0)->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (ReservationGroup $record) => $record->estaAbierto()),
            ])
            ->emptyStateHeading('Sin grupos')
            ->emptyStateDescription('Un grupo aparta varias habitaciones de una vez y las factura en un '
                .'solo documento. Es lo que necesitan las agencias, los congresos y las empresas.');
    }

    /** @return array<int, string> */
    protected static function hoteles(): array
    {
        return Hotel::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReservationGroups::route('/'),
            'create' => Pages\CreateReservationGroup::route('/create'),
            'edit' => Pages\EditReservationGroup::route('/{record}/edit'),
        ];
    }
}
