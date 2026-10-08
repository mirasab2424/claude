<?php

namespace App\Filament\Shared;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlaceForm
{
    public static function configure(Schema $schema, bool $admin = false): Schema
    {
        return $schema->components([
            Section::make('Место на карте')
                ->description('Координаты можно взять из Google/Яндекс Карт: правый клик по точке → скопировать координаты.')
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
                    TextInput::make('title')->label('Название')->required()->columnSpanFull(),
                    TextInput::make('latitude')->label('Широта')->numeric()->required()->minValue(-90)->maxValue(90),
                    TextInput::make('longitude')->label('Долгота')->numeric()->required()->minValue(-180)->maxValue(180),
                    Select::make('category_id')
                        ->label('Тип')
                        ->relationship('category', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->label())
                        ->preload(),
                    DatePicker::make('visited_at')->label('Дата посещения'),
                    Select::make('rating')
                        ->label('Оценка')
                        ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★']),
                    FileUpload::make('photo')->label('Фото')->image()->disk('public')->directory('places'),
                    Textarea::make('description')->label('Описание')->rows(4)->columnSpanFull(),
                    Toggle::make('is_public')->label('Показывать на публичной странице')->default(true),
                ]),
        ]);
    }
}
