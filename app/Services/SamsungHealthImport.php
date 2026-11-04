<?php

namespace App\Services;

use App\Enums\MetricDirection;
use App\Models\Category;
use App\Models\Metric;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        foreach ($files as $name => $content) {
            if (str_ends_with(strtolower($name), '.zip')) {
                $this->parse($this->unzip($content));
                continue;
            }
            $this->parseCsv($content);
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
                $metric = $user->metrics()->firstOrCreate(['name' => $name], [
                    'unit' => $unit,
                    'direction' => $direction,
                    'is_duration' => $duration,
                    'category_id' => Category::where('name', $category)->value('id'),
                    'is_public' => false,
                ]);
                $existing = $metric->entries()->get()->keyBy(fn ($e) => $e->date->toDateString());
                $n = 0;
                foreach ($days as $date => $value) {
                    $entry = $existing[$date] ?? null;
                    if ($entry && $entry->note !== self::NOTE) {
                        continue; // ручная запись важнее
                    }
                    if ($entry) {
                        $entry->update(['value' => $value]);
                    } else {
                        $metric->entries()->create(['date' => $date, 'value' => $value, 'note' => self::NOTE]);
                    }
                    $n++;
                }
                $result[$name] = $n;
            }
        });

        return $result;
    }

    private function parseCsv(string $content): void
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if (count($lines) < 3) {
            return;
        }
        $type = explode(',', $lines[0])[0];
        $header = str_getcsv($lines[1], escape: '');
        $rows = [];
        foreach (array_slice($lines, 2) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '');
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }

        match ($type) {
            'com.samsung.shealth.step_daily_trend' => $this->steps($rows),
            'com.samsung.shealth.sleep' => $this->sleep($rows),
            'com.samsung.shealth.exercise' => $this->exercise($rows),
            'com.samsung.shealth.tracker.heart_rate' => $this->heart($rows),
            'com.samsung.health.weight' => $this->weight($rows),
            default => null,
        };
    }

    /** Шаги и расстояние: сводка по всем устройствам (source_type = -2). */
    private function steps(array $rows): void
    {
        foreach ($rows as $r) {
            if (($r['source_type'] ?? '') !== '-2' || empty($r['day_time'])) {
                continue;
            }
            $date = substr($r['day_time'], 0, 10);
            $this->series['steps'][$date] = (float) $r['count'];
            $this->series['distance'][$date] = round((float) $r['distance'] / 1000, 2);
        }
    }

    /** Сон: длительность в минутах, записывается на дату пробуждения; дневной сон суммируется. */
    private function sleep(array $rows): void
    {
        $p = 'com.samsung.health.sleep.';
        foreach ($rows as $r) {
            $minutes = (float) ($r['sleep_duration'] ?? 0);
            if ($minutes <= 0 || empty($r[$p.'end_time'])) {
                continue;
            }
            $date = $this->localDate($r[$p.'end_time'], $r[$p.'time_offset'] ?? '');
            $this->series['sleep'][$date] = ($this->series['sleep'][$date] ?? 0) + $minutes * 60;
        }
    }

    /** Тренировки: суммарное время за день; бег (тип 1002) — километры. */
    private function exercise(array $rows): void
    {
        $p = 'com.samsung.health.exercise.';
        foreach ($rows as $r) {
            if (empty($r[$p.'start_time'])) {
                continue;
            }
            $date = $this->localDate($r[$p.'start_time'], $r[$p.'time_offset'] ?? '');
            $this->series['exercise'][$date] = ($this->series['exercise'][$date] ?? 0) + round((float) $r[$p.'duration'] / 1000);
            if (($r[$p.'exercise_type'] ?? '') === '1002') {
                $this->series['running'][$date] = round(($this->series['running'][$date] ?? 0) + (float) $r[$p.'distance'] / 1000, 2);
            }
        }
    }

    /** Пульс: самое низкое измерение за день — ближе всего к пульсу в покое. */
    private function heart(array $rows): void
    {
        $p = 'com.samsung.health.heart_rate.';
        foreach ($rows as $r) {
            $bpm = (float) ($r[$p.'heart_rate'] ?? 0);
            if ($bpm <= 0 || empty($r[$p.'start_time'])) {
                continue;
            }
            $date = $this->localDate($r[$p.'start_time'], $r[$p.'time_offset'] ?? '');
            $this->series['heart'][$date] = min($this->series['heart'][$date] ?? INF, $bpm);
        }
    }

    private function weight(array $rows): void
    {
        foreach ($rows as $r) {
            if ((float) ($r['weight'] ?? 0) > 0 && ! empty($r['start_time'])) {
                $this->series['weight'][$this->localDate($r['start_time'], $r['time_offset'] ?? '')] = (float) $r['weight'];
            }
        }
    }

    /** Время в выгрузке — UTC, рядом смещение вида «UTC+0500». */
    private function localDate(string $utc, string $offset): string
    {
        $t = CarbonImmutable::parse(substr($utc, 0, 19), 'UTC');
        if (preg_match('/UTC([+-])(\d{2})(\d{2})/', $offset, $m)) {
            $t = $t->addMinutes(($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 60 + (int) $m[3]));
        }

        return $t->toDateString();
    }

    /** Из zip берём только CSV нужных типов (верхний уровень выгрузки), остальное игнорируем. */
    private function unzip(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sh');
        file_put_contents($tmp, $content);
        $zip = new ZipArchive;
        $out = [];
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/com\.samsung\.(shealth\.step_daily_trend|shealth\.sleep|shealth\.exercise|shealth\.tracker\.heart_rate|health\.weight)\.\d+\.csv$/', $name)) {
                    $out[$name] = $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
        @unlink($tmp);

        return $out;
    }
}
