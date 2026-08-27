<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\FiscalPeriodResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FiscalPeriodGuard;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FiscalPeriodResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'accounts.view'; }
    protected static function managePermission(): string { return 'accounts.manage'; }

    protected static ?string $model = FiscalPeriod::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationLabel = 'Períodos fiscales';

    protected static ?string $modelLabel = 'Período fiscal';

    protected static ?string $pluralModelLabel = 'Períodos fiscales';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 100;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('year')
                ->label('Año')
                ->numeric()
                ->required()
                ->minValue(2000)
                ->maxValue(2100)
                ->default(now()->year),

            Forms\Components\Select::make('month')
                ->label('Mes')
                ->required()
                ->options(FiscalPeriod::MONTHS_LABELS)
                ->default(now()->month)
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                    $year = (int) ($get('year') ?? now()->year);
                    if ($state === '0' || $state === 0) {
                        $set('starts_on', Carbon::create($year, 1, 1)->toDateString());
                        $set('ends_on', Carbon::create($year, 12, 31)->toDateString());
                    } else {
                        $month = (int) $state;
                        $start = Carbon::create($year, $month, 1);
                        $set('starts_on', $start->toDateString());
                        $set('ends_on', $start->copy()->endOfMonth()->toDateString());
                    }
                }),

            Forms\Components\DatePicker::make('starts_on')
                ->label('Inicia')
                ->required(),

            Forms\Components\DatePicker::make('ends_on')
                ->label('Termina')
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    protected static function requiredModule(): ?string
    {
        return \App\Support\ModuleGate::ACCOUNTING;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->defaultSort('month', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('Período')
                    ->state(fn (FiscalPeriod $r) => $r->label())
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('starts_on')->label('Desde')->date('Y-m-d')->toggleable(),
                Tables\Columns\TextColumn::make('ends_on')->label('Hasta')->date('Y-m-d')->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => $state === 'closed' ? 'Cerrado' : 'Abierto')
                    ->badge()
                    ->color(fn (string $state) => $state === 'closed' ? 'danger' : 'success')
                    ->icon(fn (string $state) => $state === 'closed' ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open'),

                Tables\Columns\TextColumn::make('locked_at')->label('Cerrado el')->dateTime('Y-m-d H:i')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('lockedBy.name')->label('Cerrado por')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')
                    ->options(['open' => 'Abierto', 'closed' => 'Cerrado']),
                Tables\Filters\SelectFilter::make('year')->label('Año')
                    ->options(fn () => collect(range(now()->year - 5, now()->year + 1))
                        ->mapWithKeys(fn ($y) => [$y => (string) $y])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('close')
                    ->label('Cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (FiscalPeriod $r) => $r->status === FiscalPeriod::STATUS_OPEN)
                    ->requiresConfirmation()
                    ->modalHeading('Cerrar período fiscal')
                    ->modalDescription(fn (FiscalPeriod $r) => sprintf(
                        'Vas a cerrar %s. Después no se permitirá ningún asiento, pago ni movimiento de inventario con fecha entre %s y %s. Solo administradores pueden reabrirlo.',
                        $r->label(),
                        $r->starts_on?->format('Y-m-d'),
                        $r->ends_on?->format('Y-m-d'),
                    ))
                    ->action(function (FiscalPeriod $r) {
                        $r->update([
                            'status' => FiscalPeriod::STATUS_CLOSED,
                            'locked_at' => now(),
                            'locked_by_user_id' => Auth::id(),
                        ]);
                        FiscalPeriodGuard::flushCache();
                        Notification::make()->title('Período cerrado')->success()->send();
                    }),

                Tables\Actions\Action::make('reopen')
                    ->label('Reabrir')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn (FiscalPeriod $r) => $r->status === FiscalPeriod::STATUS_CLOSED)
                    ->requiresConfirmation()
                    ->modalHeading('Reabrir período fiscal')
                    ->modalDescription('Vas a permitir nuevamente movimientos con fechas en este período. Solo úsalo si necesitas corregir algo y luego cerrar de nuevo.')
                    ->action(function (FiscalPeriod $r) {
                        $r->update([
                            'status' => FiscalPeriod::STATUS_OPEN,
                            'locked_at' => null,
                            'locked_by_user_id' => null,
                        ]);
                        FiscalPeriodGuard::flushCache();
                        Notification::make()->title('Período reabierto')->success()->send();
                    }),

                Tables\Actions\EditAction::make()->visible(fn (FiscalPeriod $r) => $r->status === 'open'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFiscalPeriods::route('/'),
            'create' => Pages\CreateFiscalPeriod::route('/create'),
            'edit' => Pages\EditFiscalPeriod::route('/{record}/edit'),
        ];
    }
}
