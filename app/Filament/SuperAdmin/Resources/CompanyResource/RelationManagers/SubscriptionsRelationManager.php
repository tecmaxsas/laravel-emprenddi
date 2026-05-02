<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;

use App\Models\Plan;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Suscripciones';

    protected static ?string $modelLabel = 'Suscripción';

    protected static ?string $pluralModelLabel = 'Suscripciones';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('plan_id')
                ->label('Plan')
                ->relationship('plan', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
                ->required()
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                    if (! $state) {
                        return;
                    }

                    $plan = Plan::find($state);
                    if (! $plan) {
                        return;
                    }

                    $set('price_paid', $plan->price);
                    $set('currency', $plan->currency);
                    $set('billing_cycle', $plan->billing_cycle);

                    if (! $get('starts_at')) {
                        $set('starts_at', now()->toDateString());
                    }

                    $startsAt = $get('starts_at') ? \Carbon\Carbon::parse($get('starts_at')) : now();
                    $endsAt = match ($plan->billing_cycle) {
                        'yearly' => $startsAt->copy()->addYear(),
                        'custom' => $startsAt->copy()->addDays(max(30, $plan->trial_days)),
                        default => $startsAt->copy()->addMonth(),
                    };
                    $set('ends_at', $endsAt->toDateString());
                }),

            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options([
                    Subscription::STATUS_TRIAL => 'Prueba (Trial)',
                    Subscription::STATUS_ACTIVE => 'Activa',
                    Subscription::STATUS_PAST_DUE => 'Mora',
                    Subscription::STATUS_CANCELLED => 'Cancelada',
                    Subscription::STATUS_EXPIRED => 'Expirada',
                    Subscription::STATUS_SUSPENDED => 'Suspendida',
                ])
                ->default(Subscription::STATUS_ACTIVE)
                ->required()
                ->native(false),

            Forms\Components\DatePicker::make('starts_at')
                ->label('Inicia')
                ->required()
                ->default(now()),

            Forms\Components\DatePicker::make('ends_at')
                ->label('Termina')
                ->required()
                ->afterOrEqual('starts_at'),

            Forms\Components\TextInput::make('price_paid')
                ->label('Precio pagado')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->default(0)
                ->required(),

            Forms\Components\Select::make('currency')
                ->label('Moneda')
                ->options(['COP' => 'COP', 'USD' => 'USD'])
                ->default('COP')
                ->required(),

            Forms\Components\Select::make('billing_cycle')
                ->label('Ciclo')
                ->options([
                    'monthly' => 'Mensual',
                    'yearly' => 'Anual',
                    'custom' => 'Personalizado',
                ])
                ->default('monthly')
                ->required()
                ->native(false),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('starts_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('plan.name')->label('Plan')->weight('semibold'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'trial' => 'warning',
                        'active' => 'success',
                        'past_due' => 'warning',
                        'cancelled', 'expired' => 'gray',
                        'suspended' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('starts_at')->label('Inicia')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('ends_at')->label('Termina')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('price_paid')->label('Precio')->money(fn ($r) => $r->currency),
                Tables\Columns\TextColumn::make('createdBy.email')->label('Asignó')->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Asignar plan')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['created_by_user_id'] = Auth::id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
