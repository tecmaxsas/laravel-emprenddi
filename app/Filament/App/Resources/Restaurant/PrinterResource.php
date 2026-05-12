<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\PrinterResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Category;
use App\Models\Location;
use App\Models\Restaurant\Printer;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PrinterResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.tables.manage'; }
    protected static function managePermission(): string { return 'restaurant.tables.manage'; }

    protected static ?string $model = Printer::class;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationLabel = 'Impresoras';

    protected static ?string $modelLabel = 'Impresora';

    protected static ?string $pluralModelLabel = 'Impresoras';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(80)
                        ->placeholder('Cocina, Barra, Caja'),

                    Forms\Components\Select::make('purpose')
                        ->label('Propósito')
                        ->options(Printer::PURPOSES)
                        ->default('kitchen')
                        ->native(false)
                        ->required(),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => Location::query()->where('is_main', true)->value('id'))
                        ->native(false),
                ]),

            Forms\Components\Section::make('Conexión')
                ->description('Cómo se imprime físicamente. Recomendado: impresora térmica con red (ESC/POS por TCP).')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('connection_type')
                        ->label('Tipo de conexión')
                        ->options(Printer::CONNECTION_TYPES)
                        ->default('network')
                        ->live()
                        ->native(false)
                        ->required(),

                    Forms\Components\TextInput::make('host')
                        ->label('IP o hostname')
                        ->placeholder('192.168.1.100')
                        ->visible(fn (Forms\Get $get) => $get('connection_type') === 'network')
                        ->required(fn (Forms\Get $get) => $get('connection_type') === 'network')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('port')
                        ->label('Puerto')
                        ->numeric()
                        ->default(9100)
                        ->visible(fn (Forms\Get $get) => $get('connection_type') === 'network')
                        ->helperText('9100 = raw ESC/POS estándar.'),

                    Forms\Components\TextInput::make('cups_queue')
                        ->label('Cola CUPS')
                        ->placeholder('HP_LaserJet_Cocina')
                        ->visible(fn (Forms\Get $get) => $get('connection_type') === 'cups')
                        ->required(fn (Forms\Get $get) => $get('connection_type') === 'cups')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('columns')
                        ->label('Ancho papel (columnas)')
                        ->numeric()
                        ->default(48)
                        ->helperText('80mm = 48 cols, 58mm = 32 cols')
                        ->minValue(20)
                        ->maxValue(80),
                ]),

            Forms\Components\Section::make('Routing — qué categorías imprime esta impresora')
                ->description('Marca las categorías cuyos productos deben llegar acá. Una orden con productos de varias categorías genera una comanda separada por impresora.')
                ->schema([
                    Forms\Components\Select::make('category_ids')
                        ->label('Categorías')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn () => Category::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Si está vacío, esta impresora recibe TODOS los productos que no estén asignados a otra impresora (fallback general).'),
                ]),

            Forms\Components\Section::make('Comportamiento')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('open_cash_drawer')
                        ->label('Abrir cajón de monedas')
                        ->helperText('Aplica solo a impresoras de caja al cobrar.')
                        ->inline(false),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true)
                        ->inline(false),

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
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->weight('semibold')->searchable(),

                Tables\Columns\TextColumn::make('purpose')
                    ->label('Propósito')
                    ->formatStateUsing(fn (string $s) => Printer::PURPOSES[$s] ?? $s)
                    ->badge(),

                Tables\Columns\TextColumn::make('connection_type')
                    ->label('Conexión')
                    ->formatStateUsing(fn (string $s) => Printer::CONNECTION_TYPES[$s] ?? $s)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('host')
                    ->label('Host:Puerto')
                    ->state(fn (Printer $p) => $p->connection_type === 'network' ? "{$p->host}:{$p->port}" : $p->cups_queue)
                    ->fontFamily('mono')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('category_ids_count')
                    ->label('Categorías')
                    ->state(fn (Printer $p) => count($p->category_ids ?? []))
                    ->alignCenter()
                    ->badge(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),
                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
                Tables\Filters\SelectFilter::make('purpose')->label('Propósito')->options(Printer::PURPOSES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrinters::route('/'),
            'create' => Pages\CreatePrinter::route('/create'),
            'edit' => Pages\EditPrinter::route('/{record}/edit'),
        ];
    }
}
