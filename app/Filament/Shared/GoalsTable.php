<?php

namespace App\Filament\Shared;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Enums\Importance;
use App\Models\Goal;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class GoalsTable
{
    public static function configure(Table $table, bool $admin = false): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('№')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.name')->label('Пользователь')->sortable()->searchable()->visible($admin),
                TextColumn::make('title')
                    ->label('Задача')
                    ->searchable()
                    ->wrap()
                    ->grow()
                    ->extraAttributes(['style' => 'min-width: 16rem'])
                    ->description(fn (Goal $record) => $record->parent?->title ? '↳ '.$record->parent->title : null),
                TextColumn::make('category.name')
                    ->label('Тип')
                    ->formatStateUsing(fn (Goal $record) => $record->category?->label())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('importance')->label('Важность')->badge()->sortable(),
                TextColumn::make('horizon')->label('Время')->sortable()->toggleable(),
                TextColumn::make('status')->label('Статус')->badge()->sortable(),
                TextColumn::make('progress')->label('Прогресс')->suffix('%')->sortable()->alignEnd(),
                TextColumn::make('due_date')
                    ->label('Дедлайн')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Goal $record) => $record->isOverdue() ? 'danger' : null),
                IconColumn::make('is_public')->label('Публично')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(GoalStatus::class)->multiple(),
                SelectFilter::make('importance')->label('Важность')->options(Importance::class),
                SelectFilter::make('horizon')->label('Время задачи')->options(Horizon::class),
                SelectFilter::make('category')->label('Тип')->relationship('category', 'name'),
                SelectFilter::make('user')->label('Пользователь')->relationship('user', 'name')->visible($admin),
                TernaryFilter::make('is_public')->label('Публично'),
            ])
            ->groups([
                Group::make('horizon')->label('Время задачи'),
                Group::make('status')->label('Статус'),
                Group::make('category.name')->label('Тип'),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('Готово')
                    ->tooltip('Отметить выполненной')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Goal $record) => ! $record->status->isClosed())
                    ->action(fn (Goal $record) => $record->update(['status' => GoalStatus::Done])),
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
