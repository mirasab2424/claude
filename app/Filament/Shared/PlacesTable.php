<?php

namespace App\Filament\Shared;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlacesTable
{
    public static function configure(Table $table, bool $admin = false): Table
    {
        return $table
            ->defaultSort('visited_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Пользователь')->visible($admin),
                TextColumn::make('title')->label('Место')->searchable(),
                TextColumn::make('category.name')->label('Тип')->badge()->color('gray'),
                TextColumn::make('visited_at')->label('Когда')->date('d.m.Y')->sortable(),
                TextColumn::make('rating')->label('Оценка')->formatStateUsing(fn ($state) => str_repeat('★', (int) $state)),
                IconColumn::make('is_public')->label('Публично')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
