<?php

namespace App\Filament\Shared;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Enums\Importance;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class GoalForm
{
    public static function configure(Schema $schema, bool $admin = false): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Задача')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Задача')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('user_id')
                            ->label('Пользователь')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible($admin),
                        Select::make('category_id')
                            ->label('Тип задачи')
                            ->relationship('category', 'name', fn (Builder $query) => $query->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->label())
                            ->preload(),
                        Select::make('parent_id')
                            ->label('Входит в цель')
                            ->helperText('Например, недельная задача внутри годовой цели')
                            ->relationship('parent', 'title', fn (Builder $query, $record) => $query
                                ->when(! $admin, fn ($q) => $q->where('user_id', auth()->id()))
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey())))
                            ->searchable()
                            ->preload(),
                        ToggleButtons::make('importance')
                            ->label('Важность')
                            ->options(Importance::class)
                            ->default(Importance::Need)
                            ->inline()
                            ->required(),
                        ToggleButtons::make('horizon')
                            ->label('Время задачи')
                            ->options(Horizon::class)
                            ->default(Horizon::Month)
                            ->inline()
                            ->required(),
                    ]),
                Section::make('Статус')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('status')
                            ->label('Статус')
                            ->options(GoalStatus::class)
                            ->default(GoalStatus::Planned)
                            ->required()
                            ->native(false),
                        TextInput::make('progress')
                            ->label('Прогресс')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->suffix('%'),
                        DatePicker::make('start_date')->label('Начало'),
                        DatePicker::make('due_date')->label('Дедлайн'),
                        Toggle::make('is_public')
                            ->label('Показывать на публичной странице')
                            ->default(true),
                    ]),
                Section::make('Заметки и выводы')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Textarea::make('notes')
                            ->label('Заметки')
                            ->helperText('Для упрощения выполнения: используются и дополняются по ходу')
                            ->rows(6),
                        Textarea::make('retrospective')
                            ->label('Комментарии')
                            ->helperText('Для анализа и выводов после выполнения')
                            ->rows(6),
                        FileUpload::make('photo')
                            ->label('Фото')
                            ->image()
                            ->disk('public')
                            ->directory('goals'),
                        TextInput::make('link')
                            ->label('Ссылка')
                            ->helperText('Например, пост в Telegram или видео')
                            ->url()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
