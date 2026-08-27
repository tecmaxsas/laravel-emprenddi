<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ExogenaManualEntryResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\ExogenaManualEntry;
use App\Services\Exogena\ExogenaCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Captura manual de los formatos de exógena que no salen del libro
 * contable (1004 descuentos tributarios, 1011 declaraciones tributarias).
 */
class ExogenaManualEntryResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'exogena.manage'; }
    protected static function managePermission(): string { return 'exogena.manage'; }

    protected static ?string $model = ExogenaManualEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Exógena — Datos Manuales';

    protected static ?string $modelLabel = 'Registro manual de exógena';

    protected static ?string $pluralModelLabel = 'Registros manuales de exógena';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 70;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dato de exógena')
                ->description('Para los formatos 1004 y 1011, que se diligencian con base en la resolución DIAN y la declaración de renta del año.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('fiscal_year')
                        ->label('Año gravable')
                        ->numeric()
                        ->minValue(2020)
                        ->maxValue(2100)
                        ->default(now()->year - 1)
                        ->required(),

                    Forms\Components\Select::make('format_code')
                        ->label('Formato')
                        ->options(ExogenaCatalog::manualFormatOptions())
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('concept_code')
                        ->label('Código del concepto')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('Ej. 8301')
                        ->helperText('Código del concepto según la resolución DIAN.'),

                    Forms\Components\TextInput::make('amount')
                        ->label('Valor')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->required(),

                    Forms\Components\TextInput::make('concept_name')
                        ->label('Descripción del concepto')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected static function requiredModule(): ?string
    {
        return \App\Support\ModuleGate::ACCOUNTING;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fiscal_year', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('fiscal_year')->label('Año')->sortable(),

                Tables\Columns\TextColumn::make('format_code')
                    ->label('Formato')
                    ->formatStateUsing(fn (string $state) => ExogenaCatalog::formatLabel($state))
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('concept_code')
                    ->label('Concepto')
                    ->formatStateUsing(fn (string $state, ExogenaManualEntry $record) => $state.' — '.$record->concept_name)
                    ->wrap(),

                Tables\Columns\TextColumn::make('amount')->label('Valor')->money('COP')->alignEnd(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('format_code')
                    ->label('Formato')
                    ->options(ExogenaCatalog::manualFormatOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin registros manuales')
            ->emptyStateDescription('Cargá los valores de los formatos 1004 y 1011 del año a reportar.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExogenaManualEntries::route('/'),
            'create' => Pages\CreateExogenaManualEntry::route('/create'),
            'edit' => Pages\EditExogenaManualEntry::route('/{record}/edit'),
        ];
    }
}
