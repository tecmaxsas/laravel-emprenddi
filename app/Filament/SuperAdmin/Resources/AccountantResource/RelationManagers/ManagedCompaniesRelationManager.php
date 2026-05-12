<?php

namespace App\Filament\SuperAdmin\Resources\AccountantResource\RelationManagers;

use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ManagedCompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'managedCompanies';

    protected static ?string $title = 'Empresas vinculadas';

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    public function form(Form $form): Form
    {
        // No se usa al adjuntar — usamos el AttachAction default
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('legal_name')
            ->columns([
                Tables\Columns\TextColumn::make('legal_name')
                    ->label('Razón social')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('document_number')
                    ->label('NIT')
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('pivot.active')
                    ->label('Activa')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('pivot.granted_at')
                    ->label('Vinculada')
                    ->dateTime('Y-m-d H:i'),

                Tables\Columns\TextColumn::make('pivot.notes')
                    ->label('Notas')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Vincular empresa')
                    ->recordSelectSearchColumns(['legal_name', 'document_number'])
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                        Forms\Components\Toggle::make('active')->label('Activa')->default(true),
                        Forms\Components\Textarea::make('notes')->label('Notas')->rows(2),
                    ])
                    ->mutateFormDataUsing(function (array $data) {
                        $data['granted_at'] = now();
                        $data['granted_by_user_id'] = Auth::id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn ($record) => $record->pivot->active ? 'Desactivar' : 'Activar')
                    ->icon(fn ($record) => $record->pivot->active ? 'heroicon-o-no-symbol' : 'heroicon-o-check')
                    ->color(fn ($record) => $record->pivot->active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->pivot->update(['active' => ! $record->pivot->active]);
                    }),

                Tables\Actions\DetachAction::make()->label('Quitar vínculo'),
            ]);
    }
}
