<?php

namespace App\Filament\App\Resources\ThirdPartyResource\RelationManagers;

use App\Models\OrderTaking\PriceList;
use App\Models\ThirdPartyBranch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Las sucursales del cliente.
 *
 * Solo tiene sentido para clientes que reciben en varias direcciones bajo el
 * mismo NIT. Quien no las use no ve nada: la pestaña aparece igual, vacía, y el
 * resto del sistema se comporta como siempre.
 */
class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    protected static ?string $title = 'Sucursales';

    protected static ?string $modelLabel = 'Sucursal';

    protected static ?string $pluralModelLabel = 'Sucursales';

    protected static ?string $icon = 'heroicon-o-building-storefront';

    public function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre de la sucursal')
                        ->placeholder('CEDI Norte, Punto Chapinero…')
                        ->required()
                        ->maxLength(150)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->helperText('El que usa el cliente en sus órdenes de compra')
                        ->maxLength(40),
                ]),

            Forms\Components\Section::make('Dónde se entrega')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->label('Dirección')
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('city')
                        ->label('Ciudad')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('department')
                        ->label('Departamento')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('delivery_horario')
                        ->label('Horario de recepción')
                        ->helperText('Despachar fuera de horario es un viaje perdido')
                        ->maxLength(150)
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('A quién se le avisa')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('contact_person')
                        ->label('Contacto')
                        ->maxLength(150),

                    Forms\Components\TextInput::make('contact_phone')
                        ->label('Teléfono del contacto')
                        ->maxLength(30),

                    Forms\Components\TextInput::make('phone')
                        ->label('Teléfono de la sucursal')
                        ->maxLength(30),

                    Forms\Components\TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(150),
                ]),

            Forms\Components\Section::make('Condiciones comerciales')
                ->description('Lo que se deje vacío se hereda del cliente. Ojo: vacío no es cero — '
                    .'un cupo vacío significa «usa el del NIT», y un cupo en cero significa '
                    .'«esta sucursal no compra a crédito».')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('default_price_list_id')
                        ->label('Lista de precios')
                        ->placeholder('La del cliente')
                        ->options(fn () => PriceList::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->native(false),

                    Forms\Components\Select::make('default_seller_user_id')
                        ->label('Vendedor')
                        ->placeholder('El del cliente')
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->native(false),

                    Forms\Components\TextInput::make('credit_limit')
                        ->label('Cupo de crédito')
                        ->placeholder('El del NIT')
                        ->helperText('Es un sub-límite: nunca por encima del cupo del cliente')
                        ->numeric()
                        ->minValue(0),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('active')
                ->label('Activa')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Sucursal')
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('address')
                    ->label('Dirección de entrega')
                    ->state(fn (ThirdPartyBranch $r) => $r->fullAddress() ?: '—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('contact_person')
                    ->label('Contacto')
                    ->description(fn (ThirdPartyBranch $r) => $r->contact_phone)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('priceList.name')
                    ->label('Lista')
                    ->placeholder('La del cliente')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Cupo')
                    ->money('COP')
                    ->placeholder('El del NIT')
                    ->alignEnd(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar sucursal')
                    ->mutateFormDataUsing(function (array $data): array {
                        // El company_id no viene del formulario: se toma del
                        // usuario. Confiar en el formulario para esto es como
                        // termina una sucursal colgando de otra empresa.
                        $data['company_id'] = auth()->user()?->company_id;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin sucursales')
            ->emptyStateDescription(
                'Agrégalas solo si este cliente recibe en varias direcciones bajo el mismo NIT. '
                .'La factura se sigue emitiendo al NIT; la sucursal dice a dónde se entrega.'
            );
    }
}
