<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ExogenaMappingResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\ExogenaAccountMapping;
use App\Services\Exogena\ExogenaCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Configuración del mapeo cuenta del PUC → concepto de información exógena.
 * Sin este mapeo el motor de exógena no puede clasificar los movimientos.
 */
class ExogenaMappingResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'exogena.manage'; }
    protected static function managePermission(): string { return 'exogena.manage'; }

    protected static ?string $model = ExogenaAccountMapping::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Conceptos Exógena';

    protected static ?string $modelLabel = 'Concepto de exógena';

    protected static ?string $pluralModelLabel = 'Conceptos de exógena';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 75;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mapeo cuenta → concepto')
                ->description('Indica a qué concepto de exógena pertenece cada cuenta contable. Una cuenta se mapea a un único concepto por formato.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('format_code')
                        ->label('Formato')
                        ->options(ExogenaCatalog::formatOptions())
                        ->required()
                        ->live()
                        ->native(false)
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('concept_code', null))
                        ->columnSpanFull(),

                    Forms\Components\Select::make('concept_code')
                        ->label('Concepto')
                        ->options(fn (Forms\Get $get) => ExogenaCatalog::conceptOptions((string) $get('format_code')))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->disabled(fn (Forms\Get $get) => ! $get('format_code'))
                        ->helperText('Elegí primero el formato.')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('account_id')
                        ->label('Cuenta contable')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'like', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)
                            ? Account::find($value)->code.' — '.Account::find($value)->name
                            : null)
                        ->unique(
                            table: ExogenaAccountMapping::class,
                            column: 'account_id',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Forms\Get $get) => $rule
                                ->where('company_id', auth()->user()?->company_id)
                                ->where('format_code', $get('format_code')),
                        )
                        ->validationMessages([
                            'unique' => 'Esa cuenta ya está mapeada a un concepto en este formato.',
                        ])
                        ->columnSpanFull(),

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
            ->defaultSort('format_code')
            ->columns([
                Tables\Columns\TextColumn::make('format_code')
                    ->label('Formato')
                    ->formatStateUsing(fn (string $state) => ExogenaCatalog::formatLabel($state))
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('concept_code')
                    ->label('Concepto')
                    ->state(fn (ExogenaAccountMapping $record) => $record->conceptName())
                    ->wrap(),

                Tables\Columns\TextColumn::make('account.code')
                    ->label('Cuenta')
                    ->fontFamily('mono')
                    ->description(fn (ExogenaAccountMapping $record) => $record->account?->name)
                    ->searchable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('format_code')
                    ->label('Formato')
                    ->options(ExogenaCatalog::formatOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin conceptos mapeados')
            ->emptyStateDescription('Mapeá las cuentas del PUC a los conceptos de cada formato para poder generar la información exógena.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExogenaMappings::route('/'),
            'create' => Pages\CreateExogenaMapping::route('/create'),
            'edit' => Pages\EditExogenaMapping::route('/{record}/edit'),
        ];
    }
}
