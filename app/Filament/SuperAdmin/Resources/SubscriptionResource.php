<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\SubscriptionResource\Pages;
use App\Models\Plan;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Suscripciones';

    protected static ?string $modelLabel = 'Suscripción';

    protected static ?string $pluralModelLabel = 'Suscripciones';

    protected static ?string $navigationGroup = 'Suscripciones';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('company_id')
                ->label('Compañía')
                ->relationship('company', 'name')
                ->required()
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('plan_id')
                ->label('Plan')
                ->relationship('plan', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
                ->required()
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
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
                ->required()
                ->native(false),

            Forms\Components\Select::make('billing_cycle')
                ->label('Ciclo')
                ->options([
                    'monthly' => 'Mensual',
                    'yearly' => 'Anual',
                    'custom' => 'Personalizado',
                ])
                ->required()
                ->native(false),

            Forms\Components\DatePicker::make('starts_at')->label('Inicia')->required(),
            Forms\Components\DatePicker::make('ends_at')->label('Termina')->required()->afterOrEqual('starts_at'),

            Forms\Components\TextInput::make('price_paid')->label('Precio pagado')->numeric()->prefix('$')->required(),
            Forms\Components\Select::make('currency')->options(['COP' => 'COP', 'USD' => 'USD'])->required(),

            Forms\Components\Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ends_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('company.name')
                    ->label('Compañía')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('plan.name')->label('Plan')->badge(),

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

                Tables\Columns\TextColumn::make('starts_at')->label('Inicia')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('ends_at')->label('Termina')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Días rest.')
                    ->formatStateUsing(fn ($state) => $state ? max(0, (int) now()->diffInDays($state, false)) : '—')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('price_paid')->label('Precio')->money(fn ($r) => $r->currency),
                Tables\Columns\TextColumn::make('createdBy.email')->label('Asignó')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        Subscription::STATUS_TRIAL => 'Trial',
                        Subscription::STATUS_ACTIVE => 'Activa',
                        Subscription::STATUS_PAST_DUE => 'Mora',
                        Subscription::STATUS_CANCELLED => 'Cancelada',
                        Subscription::STATUS_EXPIRED => 'Expirada',
                        Subscription::STATUS_SUSPENDED => 'Suspendida',
                    ]),
                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Expira en 7 días')
                    ->query(fn (Builder $q) => $q->whereBetween('ends_at', [now(), now()->addDays(7)])
                        ->whereIn('status', Subscription::ACTIVE_STATUSES)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
