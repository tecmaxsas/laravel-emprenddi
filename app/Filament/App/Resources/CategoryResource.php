<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Categorías';

    protected static ?string $modelLabel = 'Categoría';

    protected static ?string $pluralModelLabel = 'Categorías';

    protected static ?string $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(150)
                ->columnSpan(2),

            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->maxLength(160)
                ->helperText('Auto-generado desde el nombre si lo dejas vacío.')
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()->company_id)
                ),

            Forms\Components\TextInput::make('code')
                ->label('Código (opcional)')
                ->maxLength(30),

            Forms\Components\Select::make('parent_id')
                ->label('Categoría padre')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Category::query()
                    ->where('name', 'ilike', "%{$search}%")
                    ->where('id', '!=', request()->route('record'))
                    ->orderBy('name')
                    ->limit(20)
                    ->pluck('name', 'id')
                    ->all())
                ->getOptionLabelUsing(fn ($value) => Category::find($value)?->fullName())
                ->placeholder('— sin padre (raíz) —'),

            Forms\Components\TextInput::make('sort_order')
                ->label('Orden')
                ->numeric()
                ->default(0),

            Forms\Components\TextInput::make('icon')
                ->label('Icono (opcional)')
                ->maxLength(50)
                ->placeholder('heroicon-o-shopping-bag, heroicon-o-cake, ...')
                ->columnSpan(2),

            Forms\Components\Textarea::make('description')
                ->label('Descripción')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('active')
                ->label('Activa')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Padre')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Productos')
                    ->counts('products')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Subcategorías')
                    ->counts('children')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Orden')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
                Tables\Filters\Filter::make('top_level')
                    ->label('Solo raíces')
                    ->query(fn (Builder $query) => $query->whereNull('parent_id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
