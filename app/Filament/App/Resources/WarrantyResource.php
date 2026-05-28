<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WarrantyResource\Pages;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warranty;
use App\Support\WarrantiesSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tickets de garantía / RMA. Listado, búsqueda y creación. La pantalla
 * de detalle (ViewWarranty) tiene el timeline de eventos y los botones
 * de cambio de estado / asignación, que ejecutan WarrantyEngine.
 *
 * Visible solo si la feature está activada en Settings.
 */
class WarrantyResource extends Resource
{
    protected static ?string $model = Warranty::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Garantías';

    protected static ?string $modelLabel = 'Garantía';

    protected static ?string $pluralModelLabel = 'Garantías';

    protected static ?string $navigationGroup = 'Servicio';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return WarrantiesSettings::enabled()
            && (bool) auth()->user()?->can('warranties.view');
    }

    public static function canCreate(): bool
    {
        return WarrantiesSettings::enabled()
            && (bool) auth()->user()?->can('warranties.create');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('warranties.manage') && ! $record->isTerminal();
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('warranties.manage');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación del equipo')
                ->description('Busca por serial o factura para autocompletar producto y cliente. Si el equipo no se vendió por el sistema, puedes capturar todo manualmente.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('search_serial')
                        ->label('Serial del equipo')
                        ->dehydrated(false)
                        ->placeholder('Escanea o digita el serial')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! $state) return;
                            $serial = ProductSerial::query()
                                ->where('serial_number', trim($state))
                                ->with(['product', 'saleLine.invoice'])
                                ->first();
                            if (! $serial) return;
                            $set('product_serial_id', $serial->id);
                            $set('product_id', $serial->product_id);
                            $set('location_id', $serial->location_id);
                            if ($serial->saleLine?->invoice) {
                                $set('sale_invoice_id', $serial->saleLine->invoice->id);
                                $set('third_party_id', $serial->saleLine->invoice->third_party_id);
                            }
                        })
                        ->columnSpan(2),

                    Forms\Components\Select::make('sale_invoice_id')
                        ->label('Factura de venta (opcional)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => SaleInvoice::query()
                            ->where('status', 'posted')
                            ->where(function ($q) use ($search) {
                                $q->where('number', 'ilike', "%{$search}%")
                                  ->orWhereHas('customer', fn ($cq) => $cq
                                      ->where('name', 'ilike', "%{$search}%")
                                      ->orWhere('document_number', 'ilike', "%{$search}%"));
                            })
                            ->orderByDesc('date')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (SaleInvoice $i) => [
                                $i->id => $i->fullNumber().' · '.$i->customer?->name.' · '.$i->date?->format('Y-m-d'),
                            ]))
                        ->getOptionLabelUsing(fn ($value) => $value
                            ? optional(SaleInvoice::find($value))->fullNumber()
                            : null)
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! $state) return;
                            $invoice = SaleInvoice::find($state);
                            if (! $invoice) return;
                            $set('third_party_id', $invoice->third_party_id);
                            $set('location_id', $invoice->location_id);
                        })
                        ->live(),

                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Product::query()
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%")
                                  ->orWhere('barcode', 'ilike', "%{$search}%");
                            })
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => "{$p->code} — {$p->name}"]))
                        ->getOptionLabelUsing(fn ($value) => optional(Product::find($value))->name)
                        ->columnSpan(2),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                  ->orWhere('document_number', 'ilike', "%{$search}%");
                            })
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [
                                $t->id => "{$t->name} · {$t->document_number}",
                            ]))
                        ->getOptionLabelUsing(fn ($value) => optional(ThirdParty::find($value))->name),

                    Forms\Components\Hidden::make('product_serial_id'),
                ]),

            Forms\Components\Section::make('Recepción')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('location_id')
                        ->label('Sede que recibe')
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')),

                    Forms\Components\DatePicker::make('claim_date')
                        ->label('Fecha de reclamo')
                        ->required()
                        ->default(now())
                        ->maxDate(now()),

                    Forms\Components\TextInput::make('rma_number')
                        ->label('RMA / N° interno (opcional)')
                        ->maxLength(50),

                    Forms\Components\Textarea::make('reason')
                        ->label('Problema reportado por el cliente')
                        ->required()
                        ->rows(3)
                        ->placeholder('Ej: No enciende. El cliente reporta que se apagó solo después de 2 horas de uso normal.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('N°')
                    ->state(fn (Warranty $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->searchable(query: fn (Builder $q, string $search) => $q
                        ->where('prefix', 'ilike', "%{$search}%")
                        ->orWhereRaw('CAST(number AS TEXT) ILIKE ?', ["%{$search}%"])),

                Tables\Columns\TextColumn::make('rma_number')
                    ->label('RMA')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('claim_date')
                    ->label('Recibido')
                    ->date('Y-m-d'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(28),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->limit(28),

                Tables\Columns\TextColumn::make('serial.serial_number')
                    ->label('Serial')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Warranty::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Warranty::STATUS_RECEIVED => 'gray',
                        Warranty::STATUS_IN_REVIEW => 'info',
                        Warranty::STATUS_IN_REPAIR => 'warning',
                        Warranty::STATUS_RESOLVED, Warranty::STATUS_REPLACED => 'success',
                        Warranty::STATUS_REJECTED => 'danger',
                        Warranty::STATUS_DELIVERED => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('assignedUser.name')
                    ->label('Técnico')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->label('Vence')
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->color(fn (Warranty $r) => $r->isExpired() ? 'danger' : null)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Warranty::STATUSES),

                Tables\Filters\SelectFilter::make('assigned_user_id')
                    ->label('Técnico')
                    ->options(fn () => User::query()
                        ->where('company_id', auth()->user()->company_id)
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Sede')
                    ->options(fn () => Location::query()
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'product', 'serial', 'assignedUser']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarranties::route('/'),
            'create' => Pages\CreateWarranty::route('/create'),
            'view' => Pages\ViewWarranty::route('/{record}'),
        ];
    }
}
