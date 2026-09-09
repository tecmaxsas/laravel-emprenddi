<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\AuditLogResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditRegistry;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Component;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * La bitácora de auditoría.
 *
 * Solo lectura, y no por falta de tiempo: una bitácora que se puede editar no
 * responde nada. El modelo bloquea `update` y `delete`; aquí simplemente no hay
 * dónde intentarlo.
 *
 * La pregunta que esta pantalla tiene que contestar rápido es «¿quién tocó esto
 * y cuándo?», así que la fecha manda el orden y los filtros son por usuario,
 * por tipo de acción, por objeto y por rango de fechas.
 */
class AuditLogResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'audit.view';
    }

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Auditoría';

    protected static ?string $modelLabel = 'Registro de auditoría';

    protected static ?string $pluralModelLabel = 'Registros de auditoría';

    protected static ?string $navigationGroup = 'Gestión de usuarios';

    protected static ?int $navigationSort = 30;

    /** Nadie crea, edita ni borra entradas: se generan solas. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->deferLoading()
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y h:i a')
                    ->sortable()
                    ->description(fn (AuditLog $record) => $record->created_at?->diffForHumans()),

                Tables\Columns\TextColumn::make('user_name')
                    ->label('Usuario')
                    ->state(fn (AuditLog $record) => $record->actorLabel())
                    ->description(fn (AuditLog $record) => $record->user_email)
                    ->searchable(['user_name', 'user_email'])
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('event')
                    ->label('Acción')
                    ->badge()
                    ->formatStateUsing(fn (AuditLog $record) => $record->eventLabel())
                    ->color(fn (string $state) => match ($state) {
                        AuditLog::EVENT_CREATED => 'success',
                        AuditLog::EVENT_UPDATED => 'warning',
                        AuditLog::EVENT_DELETED => 'danger',
                        AuditLog::EVENT_LOGIN_FAILED => 'danger',
                        AuditLog::EVENT_LOGIN, AuditLog::EVENT_LOGOUT => 'gray',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Sobre')
                    ->formatStateUsing(fn (?string $state) => AuditRegistry::label($state))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('auditable_label')
                    ->label('Registro')
                    ->wrap()
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('changes')
                    ->label('Cambios')
                    ->state(fn (AuditLog $record) => self::resumenDeCambios($record))
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Usuario')
                    ->options(fn () => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('event')
                    ->label('Acción')
                    ->options(AuditLog::EVENTS)
                    ->multiple(),

                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Tipo de registro')
                    ->options(AuditRegistry::MODELOS)
                    ->multiple(),

                Tables\Filters\Filter::make('rango')
                    ->form([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false),
                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null,
                            fn (Builder $q, $desde) => $q->whereDate('created_at', '>=', $desde))
                        ->when($data['hasta'] ?? null,
                            fn (Builder $q, $hasta) => $q->whereDate('created_at', '<=', $hasta)))
                    ->indicateUsing(function (array $data) {
                        $indicadores = [];

                        if ($data['desde'] ?? null) {
                            $indicadores[] = 'Desde '.Carbon::parse($data['desde'])->format('d/m/Y');
                        }

                        if ($data['hasta'] ?? null) {
                            $indicadores[] = 'Hasta '.Carbon::parse($data['hasta'])->format('d/m/Y');
                        }

                        return $indicadores;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detalle')
                    ->modalHeading(fn (AuditLog $record) => $record->summary())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->infolist(fn () => self::detalle()),
            ])
            ->bulkActions([])
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay movimientos registrados')
            ->emptyStateDescription('Aquí van quedando las acciones de los usuarios: creaciones, cambios, eliminaciones e inicios de sesión.');
    }

    /** @return list<Component> */
    protected static function detalle(): array
    {
        return [
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label('Fecha y hora')
                        ->dateTime('d/m/Y h:i:s a'),
                    TextEntry::make('user_name')
                        ->label('Usuario')
                        ->state(fn (AuditLog $record) => $record->actorLabel()),
                    TextEntry::make('user_email')
                        ->label('Correo')
                        ->placeholder('—'),
                    TextEntry::make('ip_address')
                        ->label('Dirección IP')
                        ->placeholder('—')
                        ->fontFamily('mono'),
                    TextEntry::make('url')
                        ->label('Pantalla')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('user_agent')
                        ->label('Navegador')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Qué cambió')
                ->visible(fn (AuditLog $record) => filled($record->changes))
                ->schema([
                    KeyValueEntry::make('changes')
                        ->label('')
                        ->keyLabel('Campo')
                        ->valueLabel('Antes → después')
                        ->state(fn (AuditLog $record) => self::cambiosLegibles($record)),
                ]),
        ];
    }

    /**
     * Los cambios como pares «campo: antes → después».
     *
     * @return array<string, string>
     */
    public static function cambiosLegibles(AuditLog $record): array
    {
        $legibles = [];

        foreach ($record->changes ?? [] as $campo => $valor) {
            if (is_array($valor) && array_key_exists('antes', $valor)) {
                $legibles[$campo] = self::mostrar($valor['antes']).' → '.self::mostrar($valor['despues']);

                continue;
            }

            $legibles[$campo] = self::mostrar($valor);
        }

        return $legibles;
    }

    /** Las dos o tres primeras diferencias, para la columna de la tabla. */
    protected static function resumenDeCambios(AuditLog $record): ?string
    {
        $cambios = $record->changes ?? [];

        if ($cambios === []) {
            return null;
        }

        // En una creación no hay «antes»: lo útil es cuántos datos trajo, no
        // listarlos todos en una celda.
        if ($record->event === AuditLog::EVENT_CREATED) {
            return count($cambios).' '.(count($cambios) === 1 ? 'dato' : 'datos').' registrados';
        }

        $legibles = self::cambiosLegibles($record);
        $primeros = array_slice($legibles, 0, 2, true);

        $texto = collect($primeros)
            ->map(fn ($valor, $campo) => "{$campo}: {$valor}")
            ->implode(' · ');

        $restantes = count($legibles) - count($primeros);

        return $restantes > 0 ? $texto." (+{$restantes} más)" : $texto;
    }

    protected static function mostrar(mixed $valor): string
    {
        return match (true) {
            $valor === null, $valor === '' => 'vacío',
            $valor === true => 'sí',
            $valor === false => 'no',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE),
            default => (string) $valor,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
