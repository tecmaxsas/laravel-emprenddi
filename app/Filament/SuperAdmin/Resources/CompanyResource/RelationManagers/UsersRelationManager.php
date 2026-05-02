<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Usuarios';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')
                    ->formatStateUsing(fn ($record) => trim($record->name.' '.$record->last_name)),
                Tables\Columns\TextColumn::make('email')->label('Email')->copyable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Roles')->badge(),
                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
                Tables\Columns\TextColumn::make('last_login_at')->label('Último login')->dateTime()->placeholder('Nunca'),
            ]);
    }
}
