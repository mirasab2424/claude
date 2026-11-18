<?php

namespace App\Filament\Shared;

use App\Models\Metric;
use App\Services\SamsungHealthImport;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                    ->state(fn (Metric $record) => $record->entries->last() ? $record->format($record->entries->last()->value) : null)
                    ->suffix(fn (Metric $record) => $record->unit && ! $record->is_duration ? ' '.$record->unit : ''),
                TextColumn::make('target')->label('Цель')->formatStateUsing(fn (Metric $record, $state) => $record->format((float) $state)),
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
            ->recordActions([self::addEntry(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /** Быстрый замер прямо из списка: дата, значение (для длительности — м:сс), заметка. */
    public static function addEntry(): Action
    {
        return Action::make('addEntry')
            ->label('Замер')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->modalHeading(fn (Metric $record) => 'Новый замер: '.$record->name)
            ->modalSubmitActionLabel('Сохранить')
            ->schema(fn (Metric $record) => [
                DatePicker::make('date')->label('Дата')->default(now())->required()->native(false)->displayFormat('d.m.Y'),
                TextInput::make('value')->label($record->is_duration ? 'Время (м:сс или ч:мм:сс)' : 'Значение'.($record->unit ? ", {$record->unit}" : ''))
                    ->required()
                    ->placeholder($record->is_duration ? '13:52' : '')
                    ->rule(fn () => function (string $attribute, $value, $fail) use ($record) {
                        $input = str_replace(',', '.', trim((string) $value));
                        $valid = $record->is_duration
                            ? preg_match('/^\d+(?::[0-5]?\d){1,2}$|^\d+(?:\.\d+)?$/D', $input)
                            : is_numeric($input);
                        $number = $record->is_duration ? Metric::parseDuration($input) : (float) $input;
                        if (! $valid || ! is_finite($number) || abs($number) > 9999999999.99) {
                            $fail('Введите допустимое число'.($record->is_duration ? ' или время вида 13:52' : '').'.');
                        }
                    }),
                TextInput::make('note')->label('Заметка')->maxLength(255),
            ])
            ->action(function (Metric $record, array $data) {
                $value = $record->is_duration ? Metric::parseDuration($data['value']) : (float) str_replace(',', '.', $data['value']);
                $date = \Carbon\CarbonImmutable::parse($data['date'])->toDateString();
                $entry = $record->entries()->whereDate('date', $date)->first();
                $oldNote = $entry?->note === SamsungHealthImport::NOTE ? null : $entry?->note;
                $note = $data['note'] ?: $oldNote;
                $note = $note === SamsungHealthImport::NOTE ? $note.' (вручную)' : $note;
                $entry ? $entry->update(['value' => $value, 'note' => $note])
                    : $record->entries()->create(['date' => $date, 'value' => $value, 'note' => $note]);
                Notification::make()->success()->title('Записано: '.$record->format($value).($record->unit && ! $record->is_duration ? ' '.$record->unit : ''))->send();
            });
    }
}
