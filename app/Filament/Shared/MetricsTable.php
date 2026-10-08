<?php

namespace App\Filament\Shared;

use App\Models\Metric;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MetricsTable
{
    public static function configure(Table $table, bool $admin = false): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('entries'))
            ->columns([
                TextColumn::make('user.name')->label('Пользователь')->sortable()->visible($admin),
                TextColumn::make('name')->label('Метрика')->searchable(),
                TextColumn::make('last')
                    ->label('Последнее')
                    ->state(fn (Metric $record) => $record->entries->last()?->value)
                    ->suffix(fn (Metric $record) => $record->unit ? ' '.$record->unit : ''),
                TextColumn::make('target')->label('Цель')->numeric(),
                TextColumn::make('trend')
                    ->label('Динамика')
                    ->badge()
                    ->state(fn (Metric $record) => match ($record->trend()['state'] ?? null) {
                        'progress' => 'Прогресс',
                        'regress' => 'Регресс',
                        'flat' => 'Без изменений',
                        default => 'Мало данных',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Прогресс' => 'success',
                        'Регресс' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('entries_count')->label('Записей')->counts('entries'),
                IconColumn::make('is_public')->label('Публично')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
