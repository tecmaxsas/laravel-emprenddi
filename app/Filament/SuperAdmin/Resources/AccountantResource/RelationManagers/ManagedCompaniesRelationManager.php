<?php

namespace App\Filament\SuperAdmin\Resources\AccountantResource\RelationManagers;

use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
                    // using() nos da control total sobre el insert al pivot —
                    // evitamos problemas de orden/serialización de fechas y
                    // garantizamos que granted_at y granted_by_user_id queden
                    // siempre rellenados con valores válidos.
                    ->using(function (BelongsToMany $relationship, array $data) {
                        $recordId = $data['recordId'] ?? null;
                        if (! $recordId) return;

                        $relationship->syncWithoutDetaching([
                            $recordId => [
                                'active' => (bool) ($data['active'] ?? true),
                                'notes' => $data['notes'] ?? null,
                                'granted_at' => now(),
                                'granted_by_user_id' => Auth::id(),
                            ],
                        ]);
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
