<?php

namespace App\Filament\Shared;

use App\Enums\MetricDirection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MetricForm
{
    public static function configure(Schema $schema, bool $admin = false): Schema
    {
        return $schema->components([
            Section::make('Метрика')
                ->description('То, что можно измерить числом: вес, шаги, прочитанные страницы, часы практики…')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('user_id')
                        ->label('Пользователь')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->visible($admin),
                    TextInput::make('name')->label('Название')->required()->maxLength(255),
                    TextInput::make('unit')->label('Единица')->placeholder('кг, км, стр., ч')->maxLength(30),
                    Select::make('category_id')
                        ->label('Тип')
                        ->relationship('category', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->label())
                        ->preload(),
                    ToggleButtons::make('direction')
                        ->label('Что считается прогрессом')
                        ->options(MetricDirection::class)
                        ->default(MetricDirection::Up)
                        ->inline()
                        ->required(),
                    TextInput::make('target')->label('Цель (значение)')->numeric(),
                    Toggle::make('is_public')->label('Показывать на публичной странице')->default(true),
                ]),
        ]);
    }
}
