<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\AppointmentResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ThirdParty;
use App\Support\AppointmentsSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AppointmentResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'appointments.view'; }
    protected static function managePermission(): string { return 'appointments.manage'; }

    protected static ?string $model = Appointment::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Citas';
    protected static ?string $modelLabel = 'Cita';
    protected static ?string $pluralModelLabel = 'Citas';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 80;

    public static function canAccess(): bool
    {
        if (! AppointmentsSettings::moduleActive()) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos de la cita')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->searchable()
                        ->preload()
                        ->required(fn () => AppointmentsSettings::requiresClient())
                        ->placeholder('— Sin cliente —')
                        ->options(fn () => ThirdParty::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where('is_customer', true)
                            ->where('active', true)
                            ->orderBy('name')
                            ->limit(500)
                            ->pluck('name', 'id')),

                    Forms\Components\Select::make('employee_id')
                        ->label('Profesional')
                        ->searchable()
                        ->placeholder('Sin asignar')
                        ->options(fn () => Employee::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where('status', Employee::STATUS_ACTIVE)
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn ($e) => [$e->id => $e->fullName()])
                            ->all()),

                    Forms\Components\Select::make('product_id')
                        ->label('Servicio (recomendado)')
                        ->searchable()
                        ->placeholder('— Sin servicio específico —')
                        ->helperText('Recomendado: al "Atender y cobrar" se precarga en el POS. Solo aparecen productos de tipo "Servicio".')
                        ->options(fn () => Product::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where('type', 'service')
                            ->where('is_sellable', true)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state && ($p = Product::find($state))) {
                                $set('price', $p->default_sale_price);
                            }
                        }),

                    Forms\Components\TextInput::make('price')
                        ->label('Precio')
                        ->numeric()
                        ->prefix('$')
                        ->minValue(0)
                        ->helperText('Se usa al precargar la venta en el POS.'),

                    Forms\Components\DateTimePicker::make('starts_at')
                        ->label('Inicio')
                        ->required()
                        ->seconds(false)
                        ->native(false)
                        ->minutesStep(5)
                        ->default(now()->addHour()->startOfHour())
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state) {
                                $dur = AppointmentsSettings::defaultDurationMinutes();
                                $set('ends_at', Carbon::parse($state)->addMinutes($dur));
                            }
                        })
                        ->rule(static function (Forms\Get $get, ?Appointment $record) {
                            return static function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                if (AppointmentsSettings::allowsOverlap()) {
                                    return;
                                }
                                $employeeId = $get('employee_id');
                                $end = $get('ends_at');
                                if (! $employeeId || ! $value || ! $end) {
                                    return;
                                }
                                $query = Appointment::query()
                                    ->where('company_id', auth()->user()->company_id)
                                    ->where('employee_id', $employeeId)
                                    ->whereIn('status', Appointment::ACTIVE_STATUSES)
                                    ->where('starts_at', '<', $end)
                                    ->where('ends_at', '>', $value);
                                if ($record) {
                                    $query->whereKeyNot($record->getKey());
                                }
                                if ($query->exists()) {
                                    $fail('El profesional ya tiene una cita que se cruza con ese horario.');
                                }
                            };
                        }),

                    Forms\Components\DateTimePicker::make('ends_at')
                        ->label('Fin')
                        ->required()
                        ->seconds(false)
                        ->native(false)
                        ->minutesStep(5)
                        ->after('starts_at'),

                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options(Appointment::STATUSES)
                        ->default(Appointment::STATUS_SCHEDULED)
                        ->required(),

                    Forms\Components\TextInput::make('title')
                        ->label('Título / referencia')
                        ->maxLength(150)
                        ->placeholder('Opcional'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Inicio')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Cliente')
                    ->placeholder('—')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('employee_id')
                    ->label('Profesional')
                    ->state(fn (Appointment $record) => $record->employee?->fullName() ?: '—'),

                Tables\Columns\TextColumn::make('service.name')
                    ->label('Servicio')
                    ->placeholder('—')
                    ->limit(30),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Appointment::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Appointment::STATUS_COLORS[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Precio')
                    ->money('COP')
                    ->alignEnd()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Appointment::STATUSES),

                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Profesional')
                    ->options(fn () => Employee::query()
                        ->where('company_id', auth()->user()->company_id)
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn ($e) => [$e->id => $e->fullName()])
                        ->all()),

                Tables\Filters\Filter::make('range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Desde'),
                        Forms\Components\DatePicker::make('to')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('starts_at', '>=', $v))
                            ->when($data['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('starts_at', '<=', $v));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('attend')
                    ->label('Atender y cobrar')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Appointment $record) => $record->sale_invoice_id === null
                        && $record->isOpen()
                        && auth()->user()?->can('appointments.manage')
                        && auth()->user()?->can('pos.use'))
                    ->requiresConfirmation()
                    ->modalHeading('Atender y cobrar')
                    ->modalDescription('Se marcará la cita como atendida y se abrirá el POS con el cliente y el servicio precargados.')
                    ->modalSubmitActionLabel('Ir al POS')
                    ->action(function (Appointment $record) {
                        $record->update(['status' => Appointment::STATUS_ATTENDED]);

                        return redirect(\App\Filament\App\Pages\PosTerminal::getUrl(['appointment' => $record->id]));
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading('Sin citas')
            ->emptyStateDescription('Agenda tu primera cita con el botón "Nueva cita".')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointments::route('/'),
            'create' => Pages\CreateAppointment::route('/create'),
            'edit' => Pages\EditAppointment::route('/{record}/edit'),
        ];
    }
}
