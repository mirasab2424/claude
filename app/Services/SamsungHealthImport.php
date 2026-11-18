<?php

namespace App\Services;

use App\Enums\MetricDirection;
use App\Models\Category;
use App\Models\Metric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Импорт выгрузки Samsung Health (Настройки → Скачать личные данные) в метрики пользователя.
 * Принимает CSV-файлы выгрузки или zip с ними. Тип файла определяется по первой строке
 * («com.samsung.shealth.step_daily_trend,…»), поэтому имя файла не важно.
 *
 * Метрики создаются приватными. Повторный импорт обновляет значения по датам, а записи,
 * которые пользователь ввёл вручную (без пометки Samsung Health), не трогает.
 */
class SamsungHealthImport
{
    public const NOTE = 'Samsung Health';

    private const MAX_FILE_BYTES = 30 * 1024 * 1024;

    private const MAX_BATCH_BYTES = 48 * 1024 * 1024;

    private const MAX_ARCHIVE_ENTRIES = 5000;

    /** Метрики: имя, единица, направление, длительность, категория. */
    public const METRICS = [
        'steps' => ['Шаги', 'шагов', MetricDirection::Up, false, 'Здоровье'],
        'distance' => ['Пройдено за день', 'км', MetricDirection::Up, false, 'Здоровье'],
        'sleep' => ['Сон', '', MetricDirection::Up, true, 'Здоровье'],
        'heart' => ['Пульс, минимум за день', 'уд/мин', MetricDirection::Down, false, 'Здоровье'],
        'exercise' => ['Тренировки за день', '', MetricDirection::Up, true, 'Спорт'],
        'running' => ['Бег за день', 'км', MetricDirection::Up, false, 'Спорт'],
        'weight' => ['Вес', 'кг', MetricDirection::Down, false, 'Здоровье'],
    ];

    /** @var array<string, array<string, float>> ключ метрики → дата → значение */
    private array $series = [];

    /** @param array<string, string> $files имя файла → содержимое */
    public function parse(array $files): self
    {
        if (array_sum(array_map('strlen', $files)) > self::MAX_BATCH_BYTES) {
            return $this;
        }
        $remaining = self::MAX_BATCH_BYTES;
        foreach ($files as $name => $content) {
            if (strlen($content) > self::MAX_FILE_BYTES) {
                continue;
            }
            if (str_ends_with(strtolower($name), '.zip')) {
                foreach ($this->unzip($content, $remaining) as $csv) {
                    $this->parseCsv($csv);
                }
            } elseif (strlen($content) <= $remaining) {
                $remaining -= strlen($content);
                $this->parseCsv($content);
            }
        }

        return $this;
    }

    /** @return array<string, array<string, float>> */
    public function series(): array
    {
        return array_filter($this->series);
    }

    /**
     * Записывает данные в метрики пользователя.
     *
     * @return array<string, int> имя метрики → сколько дней записано
     */
    public function saveFor(User $user): array
    {
        $result = [];
        DB::transaction(function () use ($user, &$result) {
            foreach ($this->series() as $key => $days) {
                [$name, $unit, $direction, $duration, $category] = self::METRICS[$key];
                $metrics = $user->metrics()->where('name', $name)->orderBy('id')
                    ->with(['entries' => fn ($query) => $query->whereDate('date', '>=', min(array_keys($days)))
                        ->whereDate('date', '<=', max(array_keys($days)))])->get();
                $manual = $metrics->flatMap(fn ($metric) => $metric->entries)
                    ->reject(fn ($entry) => $entry->note === self::NOTE)
                    ->keyBy(fn ($entry) => $entry->date->toDateString());
                $days = array_diff_key($days, $manual->all());
                if (! $days) {
                    $result[$name] = 0;

                    continue;
                }
                $metric = $metrics->first(fn (Metric $metric) => ! $metric->is_public && $metric->is_duration === $duration);
                $existing = $metric?->entries->keyBy(fn ($entry) => $entry->date->toDateString()) ?? collect();
                $metric ??= $user->metrics()->create([
                    'name' => $name,
                    'unit' => $unit,
                    'direction' => $direction,
                    'is_duration' => $duration,
                    'category_id' => Category::where('name', $category)->value('id'),
                    'is_public' => false,
                ]);
                $n = 0;
                foreach ($days as $date => $value) {
                    $entry = $existing[$date] ?? null;
                    if ($entry) {
                        $entry->update(['value' => $value]);
                    } else {
                        $metric->entries()->create(['date' => $date, 'value' => $value, 'note' => self::NOTE]);
                    }
                    $n++;
                }
                $result[$name] = $n;
            }
        }, 3);

        return $result;
    }

    private function parseCsv(string $content): void
    {
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        if (! str_contains($content, "\n")) {
            $content = str_replace("\r", "\n", $content);
        }
        $stream = fopen('php://temp', 'w+b');
        if (! $stream) {
            return;
        }
        try {
            if (fwrite($stream, $content) !== strlen($content)) {
                return;
            }
            rewind($stream);
            $type = fgetcsv($stream, escape: '')[0] ?? '';
            $header = fgetcsv($stream, escape: '') ?: [];
            while ($header && end($header) === '') {
                array_pop($header);
            }
            if (! $header || in_array('', $header, true) || count($header) !== count(array_unique($header))) {
                return;
            }

            $rows = $this->csvRows($stream, $header);
            match ($type) {
                'com.samsung.shealth.step_daily_trend' => $this->steps($rows),
                'com.samsung.shealth.sleep' => $this->sleep($rows),
                'com.samsung.shealth.exercise' => $this->exercise($rows),
                'com.samsung.shealth.tracker.heart_rate' => $this->heart($rows),
                'com.samsung.health.weight' => $this->weight($rows),
                default => null,
            };
        } finally {
            fclose($stream);
        }
    }

    private function csvRows($stream, array $header): Generator
    {
        $width = count($header);
        while (($cells = fgetcsv($stream, escape: '')) !== false) {
            // Экспорт может добавлять пустую ячейку в конце строки.
            while (count($cells) > $width && end($cells) === '') {
                array_pop($cells);
            }
            if (count($cells) === $width) {
                yield array_combine($header, $cells);
            }
        }
    }

    private function number(?string $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0 && $number <= 9999999999.99 ? $number : null;
    }

    /** Шаги и расстояние: сводка по всем устройствам (source_type = -2). */
    private function steps(iterable $rows): void
    {
        foreach ($rows as $r) {
            if (($r['source_type'] ?? '') !== '-2' || ! ($date = $this->localDate($r['day_time'] ?? ''))) {
                continue;
            }
            $steps = $this->number($r['count'] ?? null);
            $distance = $this->number($r['distance'] ?? null);
            if ($steps !== null) {
                $this->series['steps'][$date] = $steps;
            }
            if ($distance !== null) {
                $this->series['distance'][$date] = round($distance / 1000, 2);
            }
        }
    }

    /** Сон: длительность в минутах, записывается на дату пробуждения; дневной сон суммируется. */
    private function sleep(iterable $rows): void
    {
        $p = 'com.samsung.health.sleep.';
        foreach ($rows as $r) {
            $minutes = $this->number($r['sleep_duration'] ?? null);
            $date = $this->localDate($r[$p.'end_time'] ?? '', $r[$p.'time_offset'] ?? '');
            if ($minutes > 0 && $minutes <= 1440 && $date) {
                $this->series['sleep'][$date] = ($this->series['sleep'][$date] ?? 0) + $minutes * 60;
            }
        }
    }

    /** Тренировки: суммарное время за день; бег (тип 1002) — километры. */
    private function exercise(iterable $rows): void
    {
        $p = 'com.samsung.health.exercise.';
        foreach ($rows as $r) {
            $date = $this->localDate($r[$p.'start_time'] ?? '', $r[$p.'time_offset'] ?? '');
            if (! $date) {
                continue;
            }
            $duration = $this->number($r[$p.'duration'] ?? null);
            $distance = $this->number($r[$p.'distance'] ?? null);
            if ($duration !== null) {
                $this->series['exercise'][$date] = ($this->series['exercise'][$date] ?? 0) + round($duration / 1000);
            }
            if (($r[$p.'exercise_type'] ?? '') === '1002' && $distance !== null) {
                $this->series['running'][$date] = round(($this->series['running'][$date] ?? 0) + $distance / 1000, 2);
            }
        }
    }

    /** Пульс: самое низкое измерение за день — ближе всего к пульсу в покое. */
    private function heart(iterable $rows): void
    {
        $p = 'com.samsung.health.heart_rate.';
        foreach ($rows as $r) {
            $bpm = $this->number($r[$p.'heart_rate'] ?? null);
            $date = $this->localDate($r[$p.'start_time'] ?? '', $r[$p.'time_offset'] ?? '');
            if ($bpm > 0 && $date) {
                $this->series['heart'][$date] = min($this->series['heart'][$date] ?? INF, $bpm);
            }
        }
    }

    private function weight(iterable $rows): void
    {
        foreach ($rows as $r) {
            $weight = $this->number($r['weight'] ?? null);
            $date = $this->localDate($r['start_time'] ?? '', $r['time_offset'] ?? '');
            if ($weight > 0 && $date) {
                $this->series['weight'][$date] = $weight;
            }
        }
    }

    /** Время в выгрузке — UTC, рядом смещение вида «UTC+0500». */
    private function localDate(string $utc, string $offset = ''): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d+)?)?$/D', $utc, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        $t = CarbonImmutable::parse(substr($utc, 0, 19), 'UTC');
        if ($offset !== '') {
            if (! preg_match('/^UTC([+-])(0\d|1[0-4])([0-5]\d)$/D', $offset, $m) || ($m[2] === '14' && $m[3] !== '00')) {
                return null;
            }
            $t = $t->addMinutes(($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 60 + (int) $m[3]));
        }

        return $t->toDateString();
    }

    /** Архив читается без распаковки на диск, по одному CSV и с общим лимитом. */
    private function unzip(string $content, int &$remaining): Generator
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sh');
        if ($tmp === false) {
            return;
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            if (file_put_contents($tmp, $content) !== strlen($content) || $zip->open($tmp, ZipArchive::RDONLY) !== true) {
                return;
            }
            $opened = true;
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                return;
            }
            for ($i = 0; $i < $zip->numFiles && $remaining > 0; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'] ?? '';
                if (! preg_match('~^(?:[\p{L}\p{N}_ .()-]+/)*com\.samsung\.(?:shealth\.step_daily_trend|shealth\.sleep|shealth\.exercise|shealth\.tracker\.heart_rate|health\.weight)\.\d+\.csv$~Du', $name)
                    || array_intersect(['.', '..'], explode('/', $name))) {
                    continue;
                }
                $size = (int) $entry['size'];
                if ($size <= 0 || $size > min(self::MAX_FILE_BYTES, $remaining)) {
                    continue;
                }
                $csv = $zip->getFromIndex($i, $size + 1);
                if (is_string($csv) && strlen($csv) === $size) {
                    $remaining -= $size;
                    yield $csv;
                }
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($tmp);
        }
    }
}
