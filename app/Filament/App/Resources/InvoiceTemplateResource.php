<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\InvoiceTemplateResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\InvoiceTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InvoiceTemplateResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'invoice_templates.view'; }
    protected static function managePermission(): string { return 'invoice_templates.manage'; }

    protected static ?string $model = InvoiceTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationLabel = 'Plantillas de impresión';

    protected static ?string $modelLabel = 'Plantilla';

    protected static ?string $pluralModelLabel = 'Plantillas de impresión';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(3)
                ->schema([
                    Forms\Components\Tabs::make('template')
                        ->columnSpan(2)
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('General')
                                ->icon('heroicon-o-identification')
                                ->schema(self::generalSchema()),

                            Forms\Components\Tabs\Tab::make('Encabezado')
                                ->icon('heroicon-o-document-text')
                                ->schema(self::headerSchema()),

                            Forms\Components\Tabs\Tab::make('Cliente')
                                ->icon('heroicon-o-user')
                                ->schema(self::customerSchema()),

                            Forms\Components\Tabs\Tab::make('Líneas (productos)')
                                ->icon('heroicon-o-list-bullet')
                                ->schema(self::linesSchema()),

                            Forms\Components\Tabs\Tab::make('Totales')
                                ->icon('heroicon-o-calculator')
                                ->schema(self::totalsSchema()),

                            Forms\Components\Tabs\Tab::make('Pie de página')
                                ->icon('heroicon-o-chevron-down')
                                ->schema(self::footerSchema()),
                        ]),

                    Forms\Components\Section::make('Vista previa')
                        ->columnSpan(1)
                        ->schema([
                            Forms\Components\View::make('filament.invoice-template-preview')
                                ->viewData(fn (Forms\Get $get) => [
                                    'paper_size' => $get('paper_size'),
                                    'settings' => $get('settings') ?? [],
                                    'footer_text' => $get('footer_text'),
                                ]),
                        ]),
                ]),
        ]);
    }

    protected static function generalSchema(): array
    {
        return [
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('POS 80mm — Caja principal'),

                    Forms\Components\Select::make('paper_size')
                        ->label('Tamaño de papel')
                        ->options(InvoiceTemplate::PAPER_SIZES)
                        ->required()
                        ->native(false)
                        ->default('pos_80')
                        ->live(),

                    Forms\Components\TextInput::make('description')
                        ->label('Descripción')
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\Toggle::make('is_default')
                        ->label('Plantilla por defecto')
                        ->helperText('Solo una plantilla puede ser default por empresa.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true)->live(),
                ]),
        ];
    }

    protected static function headerSchema(): array
    {
        return [
            Forms\Components\Section::make('Datos a mostrar en el encabezado')
                ->description('Información de la empresa que aparece arriba en el ticket.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('settings.header.show_logo')->label('Logo')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_business_name')->label('Nombre comercial')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_legal_name')->label('Razón social')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_nit')->label('NIT con DV')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_address')->label('Dirección')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_phone')->label('Teléfono')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_email')->label('Email')->default(false)->live(),
                    Forms\Components\Toggle::make('settings.header.show_location_name')->label('Nombre de la sede')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.header.show_dian_resolution')->label('Datos de la resolución DIAN')->default(true)->live(),
                ]),
        ];
    }

    protected static function customerSchema(): array
    {
        return [
            Forms\Components\Section::make('Datos del cliente')
                ->description('Si "Mostrar bloque de cliente" está apagado, no se imprime ningún dato del cliente.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('settings.customer.show')
                        ->label('Mostrar bloque de cliente')
                        ->default(true)
                        ->columnSpan(2)
                        ->live(),

                    Forms\Components\Toggle::make('settings.customer.show_name')
                        ->label('Nombre / razón social')
                        ->default(true)
                        ->live()
                        ->disabled(fn (Forms\Get $get) => ! $get('settings.customer.show')),
                    Forms\Components\Toggle::make('settings.customer.show_document')
                        ->label('Documento (CC/NIT)')
                        ->default(true)
                        ->live()
                        ->disabled(fn (Forms\Get $get) => ! $get('settings.customer.show')),
                    Forms\Components\Toggle::make('settings.customer.show_address')
                        ->label('Dirección')
                        ->default(false)
                        ->live()
                        ->disabled(fn (Forms\Get $get) => ! $get('settings.customer.show')),
                    Forms\Components\Toggle::make('settings.customer.show_phone')
                        ->label('Teléfono')
                        ->default(false)
                        ->live()
                        ->disabled(fn (Forms\Get $get) => ! $get('settings.customer.show')),
                    Forms\Components\Toggle::make('settings.customer.show_email')
                        ->label('Email')
                        ->default(false)
                        ->live()
                        ->disabled(fn (Forms\Get $get) => ! $get('settings.customer.show')),
                ]),
        ];
    }

    protected static function linesSchema(): array
    {
        return [
            Forms\Components\Section::make('Columnas de productos')
                ->description('Qué información aparece en cada línea de la factura.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('settings.lines.show_code')->label('Código')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_barcode')->label('Código de barras')->default(false)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_description')->label('Descripción')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_quantity')->label('Cantidad')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_unit_price')->label('Precio unitario')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_discount')->label('Descuento')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_tax')->label('Impuesto por línea')->default(false)->live(),
                    Forms\Components\Toggle::make('settings.lines.show_total')->label('Total línea')->default(true)->live(),
                ]),
        ];
    }

    protected static function totalsSchema(): array
    {
        return [
            Forms\Components\Section::make('Totales y pagos')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('settings.totals.show_subtotal')->label('Subtotal')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.totals.show_discount')->label('Descuento total')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.totals.show_tax_breakdown')->label('Desglose de impuestos')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.totals.show_total')->label('Total a pagar')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.totals.show_paid')->label('Pagado')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.totals.show_change')->label('Vuelto')->default(true)->live(),
                ]),
        ];
    }

    protected static function footerSchema(): array
    {
        return [
            Forms\Components\Section::make('Información de pie de página')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('settings.footer.show_qr_dian')->label('QR DIAN')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.footer.show_cufe')->label('CUFE')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.footer.show_resolution_info')->label('Datos de la resolución')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.footer.show_seller')->label('Nombre del vendedor / cajero')->default(true)->live(),
                    Forms\Components\Toggle::make('settings.footer.show_thanks')->label('Mensaje de agradecimiento')->default(true)->live(),
                ]),

            Forms\Components\Section::make('Texto libre de pie')
                ->description('Texto que aparece al final del ticket. Útil para devoluciones, garantías o agradecimientos personalizados.')
                ->schema([
                    Forms\Components\Textarea::make('footer_text')
                        ->label('Texto')
                        ->rows(4)
                        ->maxLength(500)
                        ->placeholder('Gracias por tu compra. Cambios y devoluciones dentro de los 8 días con factura.')
                        ->live(onBlur: true),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('is_default', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('paper_size')
                    ->label('Papel')
                    ->formatStateUsing(fn (string $state) => InvoiceTemplate::PAPER_SIZES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pos_58', 'pos_80' => 'info',
                        'a4', 'letter' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon(''),

                Tables\Columns\TextColumn::make('locations_count')
                    ->label('Sedes')
                    ->counts('locations')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->date('Y-m-d')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('paper_size')
                    ->label('Papel')
                    ->options(InvoiceTemplate::PAPER_SIZES),
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->label('Duplicar')
                    ->excludeAttributes(['is_default'])
                    ->beforeReplicaSaved(function (InvoiceTemplate $replica) {
                        $replica->name = $replica->name.' (copia)';
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Cuando una plantilla se marca como default, desmarca las demás de la
     * misma empresa. Garantiza un único default activo.
     */
    public static function ensureSingleDefault(InvoiceTemplate $template): void
    {
        if (! $template->is_default) {
            return;
        }

        DB::transaction(function () use ($template) {
            InvoiceTemplate::query()
                ->where('company_id', $template->company_id)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
        });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoiceTemplates::route('/'),
            'create' => Pages\CreateInvoiceTemplate::route('/create'),
            'edit' => Pages\EditInvoiceTemplate::route('/{record}/edit'),
        ];
    }
}
