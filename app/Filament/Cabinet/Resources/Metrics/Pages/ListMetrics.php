<?php

namespace App\Filament\Cabinet\Resources\Metrics\Pages;

use App\Filament\Cabinet\Resources\Metrics\MetricResource;
use App\Services\SamsungHealthImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListMetrics extends ListRecords
{
    protected static string $resource = MetricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('samsungHealth')
                ->label('Импорт Samsung Health')
                ->icon(Heroicon::OutlinedHeart)
                ->color('gray')
                ->modalHeading('Импорт из Samsung Health')
                ->modalSubmitActionLabel('Импортировать')
                ->schema([
                    Text::make('В Samsung Health: Настройки → Скачать личные данные. Из папки выгрузки выберите CSV-файлы '
                        .'step_daily_trend, sleep, exercise, tracker.heart_rate и health.weight — или всю папку в zip. '
                        .'Появятся метрики: шаги, пройдено за день, сон, пульс, тренировки, бег, вес. Новые метрики приватные; '
                        .'повторный импорт обновит даты, ручные записи не трогает.'),
                    FileUpload::make('files')
                        ->label('Файлы выгрузки')
                        ->multiple()
                        ->storeFiles(false)
                        ->maxSize(30 * 1024)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/zip', 'application/x-zip-compressed'])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $files = [];
                    foreach ((array) $data['files'] as $file) {
                        if ($file instanceof TemporaryUploadedFile) {
                            $files[$file->getClientOriginalName()] = $file->get();
                        }
                    }
                    $result = (new SamsungHealthImport)->parse($files)->saveFor(auth()->user());
                    if (! $result) {
                        Notification::make()->warning()->title('Нужных данных не нашлось')
                            ->body('Проверьте, что выбраны CSV из выгрузки Samsung Health.')->send();

                        return;
                    }
                    Notification::make()->success()->title('Импорт готов')
                        ->body(collect($result)->map(fn ($n, $name) => "$name: $n дн.")->join(', '))->send();
                }),
            CreateAction::make(),
        ];
    }
}
