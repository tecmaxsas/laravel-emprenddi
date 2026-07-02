<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\PrinterResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Category;
use App\Models\Location;
use App\Models\Restaurant\Printer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PrinterResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'printers.manage'; }
    protected static function managePermission(): string { return 'printers.manage'; }

    protected static ?string $model = Printer::class;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationLabel = 'Impresoras';

    protected static ?string $modelLabel = 'Impresora';

    protected static ?string $pluralModelLabel = 'Impresoras';

    protected static ?string $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 20;

    /**
     * La configuración de impresoras es transversal — sirve a restaurante,
     * retail y cualquier negocio. No depende del módulo restaurante.
     */
    public static function canAccess(): bool
    {
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
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_main', true)
                            ->value('id'))
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

                    Forms\Components\TextInput::make('printer_name')
                        ->label('Nombre de la impresora en Windows')
                        ->placeholder('Digital POS e200i')
                        ->visible(fn (Forms\Get $get) => $get('connection_type') === 'browser')
                        ->required(fn (Forms\Get $get) => $get('connection_type') === 'browser')
                        ->helperText('El nombre EXACTO como aparece en Windows → Configuración → Impresoras. Requiere QZ Tray instalado en la PC del cajero.')
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
                ->description('Marca las categorías cuyos productos deben llegar acá. Una orden con productos de varias categorías genera una comanda separada por impresora. Aplica a impresoras de Cocina/Barra; las de Caja imprimen el recibo del cliente y no usan este routing.')
                ->schema([
                    Forms\Components\Select::make('category_ids')
                        ->label('Categorías')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn () => Category::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Marca TODAS las categorías que esta impresora debe imprimir. ⚠ Si la dejas vacía, esta impresora NO recibe ninguna comanda. Un producto cuya categoría no esté en ninguna impresora se marca enviado pero no imprime — asigna cada categoría explícitamente.'),
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
                    ->formatStateUsing(fn (string $state) => Printer::PURPOSES[$state] ?? $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('connection_type')
                    ->label('Conexión')
                    ->formatStateUsing(fn (string $state) => Printer::CONNECTION_TYPES[$state] ?? $state)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('host')
                    ->label('Host:Puerto')
                    ->state(fn (Printer $record) => $record->connection_type === 'network' ? "{$record->host}:{$record->port}" : $record->cups_queue)
                    ->fontFamily('mono')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('category_ids_count')
                    ->label('Categorías')
                    ->state(fn (Printer $record) => count($record->category_ids ?? []))
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
                Tables\Actions\Action::make('test')
                    ->label('Probar')
                    ->icon('heroicon-o-printer')
                    ->color('info')
                    ->action(function (Printer $record, $livewire) {
                        $result = app(\App\Services\Restaurant\PrinterTester::class)->test($record);

                        if ($result['mode'] === 'browser') {
                            if (! $result['ok']) {
                                \Filament\Notifications\Notification::make()
                                    ->title('No se pudo preparar la prueba')
                                    ->body($result['message'])
                                    ->danger()->send();
                                return;
                            }
                            // Despachar el job a QZ Tray via el bridge JS.
                            $jobs = app(\App\Services\Restaurant\BrowserPrintQueue::class)->flush();
                            $livewire->dispatch('qz-print-jobs', jobs: $jobs);
                            \Filament\Notifications\Notification::make()
                                ->title('Prueba enviada a QZ Tray')
                                ->body('Revisa la impresora. Si no sale nada, verifica que QZ Tray esté corriendo.')
                                ->success()->send();
                            return;
                        }

                        // network / cups: resultado server-side directo
                        if ($result['ok']) {
                            \Filament\Notifications\Notification::make()
                                ->title('Impresión de prueba OK')
                                ->body('Revisá la impresora.')
                                ->success()->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Falló la impresión')
                                ->body($result['message'])
                                ->danger()->send();
                        }
                    }),
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
