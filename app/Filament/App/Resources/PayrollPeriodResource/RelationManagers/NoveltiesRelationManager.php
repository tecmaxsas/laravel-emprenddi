<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\RelationManagers;

use App\Models\Employee;
use App\Models\PayrollNovelty;
use App\Services\Payroll\PayrollNoveltyCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NoveltiesRelationManager extends RelationManager
{
    protected static string $relationship = 'novelties';

    protected static ?string $title = 'Novedades';

    protected static ?string $modelLabel = 'Novedad';

    protected static ?string $pluralModelLabel = 'Novedades';

    protected static ?string $icon = 'heroicon-o-plus-circle';

    /**
     * Las novedades se gestionan mientras la nómina no esté contabilizada
     * y el usuario tenga permiso para liquidar.
     */
    protected function periodEditable(): bool
    {
        return ! $this->getOwnerRecord()->isPosted()
            && (bool) auth()->user()?->can('payroll.periods.manage');
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('employee_id')
                ->label('Empleado')
                ->options(fn () => Employee::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('status', Employee::STATUS_ACTIVE)
                    ->orderBy('first_name')
                    ->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
                    ->all())
                ->searchable()
                ->required()
                ->columnSpanFull(),

            Forms\Components\Select::make('concept_code')
                ->label('Concepto')
                ->options(PayrollNoveltyCatalog::options())
                ->searchable()
                ->required()
                ->native(false)
                ->helperText('Los devengados salariales suman a la base de salud, pensión y prestaciones.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('quantity')
                ->label('Cantidad (opcional)')
                ->numeric()
                ->minValue(0)
                ->helperText('Ej. número de horas. Solo informativo.'),

            Forms\Components\TextInput::make('amount')
                ->label('Valor')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('Observaciones')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee'))
            ->columns([
                Tables\Columns\TextColumn::make('employee')
                    ->label('Empleado')
                    ->state(fn (PayrollNovelty $record) => $record->employee?->fullName())
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('concept_code')
                    ->label('Concepto')
                    ->state(fn (PayrollNovelty $record) => $record->conceptName())
                    ->wrap(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->state(fn (PayrollNovelty $record) => $record->isEarning() ? 'Devengado' : 'Deducción')
                    ->badge()
                    ->color(fn (PayrollNovelty $record) => $record->isEarning() ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cant.')
                    ->placeholder('—')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Cargar novedad')
                    ->visible(fn () => $this->periodEditable()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => $this->periodEditable()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => $this->periodEditable()),
            ])
            ->emptyStateHeading('Sin novedades')
            ->emptyStateDescription('Cargá horas extra, bonificaciones, préstamos u otras novedades antes de liquidar.');
    }
}
