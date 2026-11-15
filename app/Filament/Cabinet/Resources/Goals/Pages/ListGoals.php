<?php

namespace App\Filament\Cabinet\Resources\Goals\Pages;

use App\Filament\Cabinet\Resources\Goals\GoalResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListGoals extends ListRecords
{
    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Загрузить CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading('Загрузить цели из CSV')
                ->modalDescription('Формат как у «Скачать CSV»: первая строка — заголовки, обязательна колонка «Цель». Можно скачать, дописать цели в Excel и загрузить обратно — повторы (то же название и срок) пропускаются.')
                ->modalSubmitActionLabel('Загрузить')
                ->schema([
                    \Filament\Forms\Components\FileUpload::make('file')->label('Файл CSV')->storeFiles(false)->maxSize(5 * 1024)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->required(),
                ])
                ->action(function (array $data) {
                    $file = $data['file'];
                    $r = (new \App\Services\GoalsCsvImport)->import(auth()->user(), $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? $file->get() : '');
                    $n = \Filament\Notifications\Notification::make()->title("Добавлено целей: {$r['created']}".($r['skipped'] ? ", пропущено повторов: {$r['skipped']}" : ''));
                    ($r['errors'] ? $n->warning()->body(implode(' ', array_slice($r['errors'], 0, 3))) : $n->success())->send();
                }),
            Action::make('exportCsv')
                ->label('Скачать CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function () {
                    $goals = auth()->user()->goals()->with(['category', 'parent'])->orderBy('created_at')->get();

                    return response()->streamDownload(function () use ($goals) {
                        $out = fopen('php://output', 'w');
                        fwrite($out, "\xEF\xBB\xBF"); // BOM — чтобы Excel открыл кириллицу
                        fputcsv($out, ['Цель', 'Тип', 'Важность', 'Горизонт', 'Статус', 'Прогресс, %', 'Начало', 'Срок', 'Выполнено', 'Внутри цели', 'Заметки', 'Выводы', 'Публично'], ';', escape: '');
                        foreach ($goals as $g) {
                            fputcsv($out, [
                                $g->title, $g->category?->name, $g->importance?->getLabel(), $g->horizon?->getLabel(), $g->status?->getLabel(), $g->progress,
                                $g->start_date?->toDateString(), $g->due_date?->toDateString(), $g->completed_at?->toDateString(), $g->parent?->title,
                                $g->notes, $g->retrospective, $g->is_public ? 'да' : 'нет',
                            ], ';', escape: '');
                        }
                        fclose($out);
                    }, 'goals-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            CreateAction::make(),
        ];
    }
}
