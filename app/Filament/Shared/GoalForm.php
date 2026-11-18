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
    /** Шаблоны частых целей: подпись, название, горизонт, тип. */
    public const TEMPLATES = [
        'run' => ['🏃 Пробежка', 'Пробежать 5 км', 'day', 'Спорт'],
        'workout' => ['💪 Тренировка', 'Тренировка: подтягивания, отжимания, пресс', 'day', 'Спорт'],
        'book' => ['📚 Прочитать книгу', 'Прочитать книгу «…»', 'month', 'Знание'],
        'course' => ['🎓 Пройти курс', 'Пройти курс «…»', 'month', 'Навык'],
        'habit_week' => ['🍬 Неделя без сладкого', 'Неделя без сладкого', 'week', 'Здоровье'],
        'sleep_week' => ['🌙 Неделя режима сна', 'Неделю ложиться до 23:00', 'week', 'Здоровье'],
        'save' => ['💰 Отложить деньги', 'Отложить … ₸ за месяц', 'month', 'Финансы'],
        'trip' => ['✈️ Поездка', 'Съездить в …', 'year', 'Путешествия'],
    ];

    public static function configure(Schema $schema, bool $admin = false): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Задача')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('template')
                            ->label('Быстрый старт: шаблон')
                            ->helperText('Заполнит название, тип и горизонт — дальше поправьте под себя')
                            ->options(collect(self::TEMPLATES)->mapWithKeys(fn ($t, $k) => [$k => $t[0]]))
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (! $state || ! isset(self::TEMPLATES[$state])) {
                                    return;
                                }
                                [, $title, $horizon, $category] = self::TEMPLATES[$state];
                                $set('title', $title);
                                $set('horizon', $horizon);
                                $set('category_id', \App\Models\Category::where('name', $category)->value('id'));
                            })
                            ->visibleOn('create')
                            ->columnSpanFull(),
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
                            ->rules(fn ($record) => $admin ? [] : [
                                \Illuminate\Validation\Rule::exists('goals', 'id')->where('user_id', auth()->id())
                                    ->when($record, fn ($rule) => $rule->whereNot('id', $record->getKey())),
                            ])
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
                            ->directory('goals')->preventFilePathTampering(),
                        TextInput::make('link')
                            ->label('Ссылка')
                            ->helperText('Например, пост в Telegram или видео')
                            ->url()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
