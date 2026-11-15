<?php

namespace App\Filament\Cabinet\Resources\Habits;

use App\Filament\Cabinet\Resources\Habits\Pages\ManageHabits;
use App\Models\Habit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Привычки: ежедневные отметки и серии. */
class HabitResource extends Resource
{
    protected static ?string $model = Habit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Записи';

    protected static ?string $modelLabel = 'привычку';

    protected static ?string $pluralModelLabel = 'Привычки';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id())->with('checks');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Привычка')->placeholder('Например: зарядка 10 минут')->required()->maxLength(80),
            TextInput::make('icon')->label('Значок (эмодзи)')->placeholder('💪')->maxLength(8),
            Select::make('days_per_week')->label('План')->options(collect(range(1, 7))->mapWithKeys(fn ($n) => [$n => $n === 7 ? 'каждый день' : "$n дн. в неделю"]))->default(7)->required(),
            Toggle::make('is_public')->label('Показывать на публичной странице'),
            Toggle::make('archived')->label('В архиве (не показывать в списке на сегодня)')
                ->afterStateHydrated(fn ($component, ?Habit $record) => $component->state((bool) $record?->archived_at))
                ->dehydrated(false)
                ->afterStateUpdated(fn ($state, ?Habit $record) => $record?->update(['archived_at' => $state ? now() : null]))
                ->live()
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Привычка')->formatStateUsing(fn (Habit $record) => trim(($record->icon ?? '').' '.$record->name))
                    ->description(fn (Habit $record) => $record->archived_at ? 'в архиве' : null),
                TextColumn::make('today')->label('Сегодня')->state(fn (Habit $record) => $record->isDoneOn(now()->toImmutable()) ? '✅' : '—'),
                TextColumn::make('streak')->label('Серия')->state(fn (Habit $record) => $record->streak().' дн.'),
                TextColumn::make('week')->label('Неделя')->state(fn (Habit $record) => $record->thisWeek().' / '.$record->days_per_week),
                TextColumn::make('best')->label('Рекорд')->state(fn (Habit $record) => $record->bestStreak().' дн.'),
                IconColumn::make('is_public')->label('Публично')->boolean(),
            ])
            ->recordActions([
                Action::make('toggleToday')
                    ->label(fn (Habit $record) => $record->isDoneOn(now()->toImmutable()) ? 'Снять' : 'Сделано')
                    ->icon(fn (Habit $record) => $record->isDoneOn(now()->toImmutable()) ? 'heroicon-o-x-mark' : 'heroicon-o-check')
                    ->color(fn (Habit $record) => $record->isDoneOn(now()->toImmutable()) ? 'gray' : 'success')
                    ->action(fn (Habit $record) => $record->toggle()),
                Action::make('toggleYesterday')
                    ->label(fn (Habit $record) => $record->isDoneOn(now()->toImmutable()->subDay()) ? 'Снять вчера' : 'Вчера')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->tooltip('Забыли отметить вчера — отметьте сейчас')
                    ->action(fn (Habit $record) => $record->toggle(now()->toImmutable()->subDay())),
                ActionGroup::make([EditAction::make(), DeleteAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageHabits::route('/')];
    }
}
