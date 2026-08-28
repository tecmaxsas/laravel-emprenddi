<?php

namespace App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers;

use App\Models\OrderTaking\Payment;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Todos los abonos del pedido, de solo lectura.
 *
 * Se registran desde el despacho al que pertenecen; aqui se ven juntos para
 * cuadrar la cartera. Los que quedaron sin despacho son anteriores al cambio
 * de flujo y se marcan como historicos.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Abonos recibidos';

    protected static ?string $modelLabel = 'Abono';

    protected static ?string $pluralModelLabel = 'Abonos';

    public function form(Form $form): Form
    {
        // Los abonos se registran desde su despacho, nunca desde aqui.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('payment_date', 'desc')
            ->emptyStateHeading('Sin abonos')
            ->emptyStateDescription('Los abonos se registran dentro de cada despacho.')
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('delivery')
                    ->label('Despacho')
                    ->state(fn (Payment $record) => $record->delivery?->label() ?? 'Histórico (sin despacho)')
                    ->color(fn (Payment $record) => $record->delivery ? null : 'gray')
                    ->description(fn (Payment $record) => $record->delivery
                        ? null
                        : 'Registrado antes de que los abonos se ligaran al despacho.'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')->money('COP')->alignEnd()->weight('semibold'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método')->badge()
                    ->formatStateUsing(fn (?string $state) => Payment::METHODS[$state] ?? (string) $state),

                Tables\Columns\TextColumn::make('reference')->label('Referencia')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Registró')->placeholder('—')->toggleable(),
            ])
            ->headerActions([])
            ->actions([])
            ->paginated(false);
    }
}
