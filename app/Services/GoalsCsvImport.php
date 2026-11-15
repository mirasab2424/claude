<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Enums\Importance;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Импорт целей из CSV в формате выгрузки «Скачать CSV» (разделитель «;», первая строка — заголовки).
 * Цели с таким же названием и сроком пропускаются, поэтому повторная загрузка не плодит дубли.
 * Обязательна только колонка «Цель»; новые цели приватные, если в «Публично» не написано «да».
 */
class GoalsCsvImport
{
    /** @return array{created: int, skipped: int, errors: list<string>} */
    public function import(User $user, string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $rows = $this->parse($csv);
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), array_shift($rows) ?? []);
        $col = fn (array $row, string $name) => trim((string) ($row[array_search($name, $header, true)] ?? ''));
        if (! in_array('цель', $header, true)) {
            return ['created' => 0, 'skipped' => 0, 'errors' => ['Нет колонки «Цель» в первой строке.']];
        }
        $label = fn (string $enum, string $text) => collect($enum::cases())->first(fn ($c) => mb_strtolower($c->getLabel()) === mb_strtolower($text));
        $date = function (string $v) { try { return $v === '' ? null : CarbonImmutable::parse($v)->toDateString(); } catch (\Throwable) { return null; } };
        $categories = Category::all()->keyBy(fn ($c) => mb_strtolower($c->name));
        $created = $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $col, $label, $date, $categories, $user, &$created, &$skipped, &$errors) {
            foreach ($rows as $i => $row) {
                $title = $col($row, 'цель');
                if ($title === '') {
                    continue;
                }
                if (mb_strlen($title) > 255) {
                    $errors[] = 'Строка '.($i + 2).': слишком длинное название.';
                    continue;
                }
                $due = $date($col($row, 'срок'));
                if ($user->goals()->where('title', $title)->when($due, fn ($q) => $q->whereDate('due_date', $due), fn ($q) => $q->whereNull('due_date'))->exists()) {
                    $skipped++;
                    continue;
                }
                $status = $label(GoalStatus::class, $col($row, 'статус')) ?? GoalStatus::Planned;
                $user->goals()->create([
                    'title' => $title,
                    'category_id' => $categories[mb_strtolower($col($row, 'тип'))]->id ?? null,
                    'importance' => $label(Importance::class, $col($row, 'важность')) ?? Importance::Need,
                    'horizon' => $label(Horizon::class, $col($row, 'горизонт')) ?? Horizon::Day,
                    'status' => $status,
                    'progress' => max(0, min(100, (int) $col($row, 'прогресс, %'))),
                    'start_date' => $date($col($row, 'начало')),
                    'due_date' => $due,
                    'completed_at' => $status === GoalStatus::Done ? ($date($col($row, 'выполнено')) ?? $due) : null,
                    'notes' => $col($row, 'заметки') ?: null,
                    'retrospective' => $col($row, 'выводы') ?: null,
                    'is_public' => mb_strtolower($col($row, 'публично')) === 'да',
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    /** CSV с «;» или «,», переносы строк внутри кавычек поддерживаются. */
    private function parse(string $csv): array
    {
        $first = strtok($csv, "\n");
        $sep = substr_count((string) $first, ';') >= substr_count((string) $first, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($fh);

        return $rows;
    }
}
