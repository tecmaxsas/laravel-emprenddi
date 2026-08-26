<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PartnerResource\Pages;
use App\Filament\App\Resources\PartnerResource\RelationManagers\MovementsRelationManager;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Partner;
use App\Models\ThirdParty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'partners.view'; }
    protected static function managePermission(): string { return 'partners.manage'; }

    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Libro de Socios';

    protected static ?string $modelLabel = 'Socio';

    protected static ?string $pluralModelLabel = 'Socios';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('person_type')
                        ->label('Tipo de persona')
                        ->options(Partner::PERSON_TYPES)
                        ->default('natural')
                        ->native(false)
                        ->required(),

                    Forms\Components\Select::make('document_type')
                        ->label('Tipo de documento')
                        ->options(ThirdParty::DOCUMENT_TYPES)
                        ->default('cc')
                        ->native(false)
                        ->required(),

                    Forms\Components\TextInput::make('document_number')
                        ->label('Número de documento')
                        ->required()
                        ->maxLength(30),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre / Razón social')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(3),

                    Forms\Components\TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('Teléfono')
                        ->maxLength(40)
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('Vinculación')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('partner_type')
                        ->label('Tipo de socio')
                        ->options(Partner::PARTNER_TYPES)
                        ->default('socio')
                        ->native(false)
                        ->required(),

                    Forms\Components\DatePicker::make('joined_at')
                        ->label('Fecha de ingreso')
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options(Partner::STATUSES)
                        ->default(Partner::STATUS_ACTIVE)
                        ->native(false)
                        ->live()
                        ->required(),

                    Forms\Components\DatePicker::make('withdrawn_at')
                        ->label('Fecha de retiro')
                        ->visible(fn (Forms\Get $get) => $get('status') === Partner::STATUS_WITHDRAWN)
                        ->required(fn (Forms\Get $get) => $get('status') === Partner::STATUS_WITHDRAWN),

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('movements'))
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Socio')
                    ->weight('semibold')
                    ->description(fn (Partner $record) => trim(strtoupper((string) $record->document_type).' '.$record->document_number))
                    ->searchable(),

                Tables\Columns\TextColumn::make('partner_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => Partner::PARTNER_TYPES[$state] ?? $state)
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('quotas')
                    ->label('Cuotas / Acciones')
                    ->state(fn (Partner $record) => number_format($record->totalQuotas(), 0, ',', '.'))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('contributed')
                    ->label('Capital aportado')
                    ->state(fn (Partner $record) => $record->totalContributed())
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('participation')
                    ->label('% Participación')
                    ->state(fn (Partner $record) => number_format($record->participationPercent(), 2, ',', '.').' %')
                    ->alignEnd()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('joined_at')
                    ->label('Ingreso')
                    ->date('Y-m-d')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => Partner::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => $state === Partner::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Partner::STATUSES),
                Tables\Filters\SelectFilter::make('partner_type')
                    ->label('Tipo de socio')
                    ->options(Partner::PARTNER_TYPES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('Sin socios registrados')
            ->emptyStateDescription('Registrá los socios o accionistas y sus aportes de capital.');
    }

    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}
