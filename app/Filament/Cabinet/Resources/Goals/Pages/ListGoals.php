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
