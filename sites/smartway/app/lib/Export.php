<?php

namespace Smart;

use PDO;
use Smart\Diag\Store;
use ZipArchive;

/**
 * Выгрузка данных админки: заявки и результаты тестов в Excel (.xlsx), PDF и CSV,
 * плюс полный архив (ZIP) — чтобы данные не потерялись, если с хостингом что-то случится.
 */
final class Export
{
    private const LEVELS = ['high' => 'Высокий', 'good' => 'Хороший', 'base' => 'Базовый', 'low' => 'Нужна поддержка'];

    public function __construct(private App $app)
    {
    }

    /** Таблица заявок: заголовок, колонки, строки, ширины колонок для PDF (доли). */
    public function leads(): array
    {
        $rows = [];
        foreach ($this->app->leads->list(null, 1000000) as $l) {
            $rows[] = [(int) $l['id'], $l['created_at'], $l['name'], $l['phone'], $l['child'], $l['course'], $l['branch'], strtoupper((string) $l['lang']),
                $l['page'], Leads::STATUSES[$l['status']] ?? $l['status'], $l['note']];
        }

        return [
            'title' => 'Заявки',
            'cols' => ['№', 'Дата', 'Имя', 'Телефон', 'Ребёнок', 'Направление', 'Филиал', 'Язык', 'Страница', 'Статус', 'Заметка'],
            'widths' => [4, 11, 11, 11, 9, 22, 8, 4, 6, 8, 16],
            'rows' => $rows,
        ];
    }

    /** Таблица результатов тестов (без демо-прохождений). */
    public function results(): array
    {
        $store = $this->app->diag();
        $labels = $store->labels();
        $topicNames = Content::localize($store->data()['topics'], 'ru');
        $rows = [];
        foreach ($store->list([], 1000000) as $a) {
            $topics = [];
            foreach ($a['topics'] as $key => [$s, $m]) {
                $topics[] = ($topicNames[$key] ?? $key) . ' ' . ($m ? round($s / $m * 100) : 0) . '%';
            }
            $minutes = $a['finished_at'] ? max(1, (int) round((strtotime($a['finished_at']) - strtotime($a['created_at'])) / 60)) : null;
            $rows[] = [(int) $a['id'], $a['finished_at'], $a['name'], $a['phone'], $labels[(int) $a['grade']] ?? $a['grade'] . ' класс',
                strtoupper((string) $a['lang']), (float) $a['score'], (float) $a['max_score'], round((float) $a['percent'], 1),
                self::LEVELS[Store::level((float) $a['percent'])], $minutes, implode(', ', $topics),
                $a['lead_status'] ? (Leads::STATUSES[$a['lead_status']] ?? $a['lead_status']) : ''];
        }

        return [
            'title' => 'Результаты тестов',
            'cols' => ['№', 'Дата', 'Имя', 'Телефон', 'Тест', 'Язык', 'Баллы', 'Из', '%', 'Уровень', 'Минут', 'По темам', 'Статус заявки'],
            'widths' => [4, 11, 11, 11, 12, 4, 5, 4, 5, 9, 5, 26, 9],
            'rows' => $rows,
        ];
    }

    public function table(string $what): array
    {
        return $what === 'results' ? $this->results() : $this->leads();
    }

    // ---------- CSV ----------

    public function csv(array $t): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel сразу видит кириллицу
        fputcsv($out, $t['cols'], ';');
        foreach ($t['rows'] as $row) {
            fputcsv($out, array_map([self::class, 'safe'], $row), ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Защита от формул при открытии в Excel: «=…», «+…» и т. п. становятся текстом. */
    private static function safe(mixed $v): mixed
    {
        return is_string($v) && preg_match('~^[=+\-@\t\r]~', $v) ? "'" . $v : $v;
    }

    // ---------- Excel (.xlsx) ----------

    /** Книга Excel из нескольких листов: [['title' => …, 'cols' => …, 'rows' => …], …]. */
    public function xlsx(array $sheets): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $n = count($sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = $wbRels = '';
        foreach ($sheets as $i => $s) {
            $k = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::x(mb_substr($s['title'], 0, 31)) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $wbRels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $z->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->sheet($s));
        }
        $z->addFromString('[Content_Types].xml', $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // стиль 1 — жирный заголовок с заливкой
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F6470"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $z->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }

    private function sheet(array $s): string
    {
        $cols = '';
        foreach ($s['cols'] as $i => $c) {
            $max = mb_strlen($c);
            foreach (array_slice($s['rows'], 0, 300) as $r) {
                $max = max($max, mb_strlen((string) ($r[$i] ?? '')));
            }
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(6, $max + 2)) . '" customWidth="1"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>';
        $all = array_merge([$s['cols']], $s['rows']);
        foreach ($all as $ri => $row) {
            $xml .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $v) {
                $ref = self::col($ci) . ($ri + 1);
                $style = $ri === 0 ? ' s="1"' : '';
                if ($ri > 0 && (is_int($v) || is_float($v))) {
                    $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } elseif ($v !== null && $v !== '') {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $last = self::col(count($s['cols']) - 1) . max(1, count($all));

        return $xml . '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    private static function x(string $s): string
    {
        // убираем управляющие символы, которые запрещены в XML
        return htmlspecialchars(preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ---------- PDF ----------

    public function pdf(array $t): string
    {
        $pdf = new \tFPDF('L', 'mm', 'A4');
        $pdf->SetTitle('SMART WAY — ' . $t['title'], true);
        $pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
        $pdf->SetMargins(8, 10, 8);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->SetFont('DejaVu', 'B', 14);
        $pdf->Cell(0, 8, 'SMART WAY — ' . $t['title'], 0, 1);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->SetTextColor(100, 110, 115);
        $pdf->Cell(0, 5, 'Выгрузка от ' . date('d.m.Y H:i') . ' · записей: ' . count($t['rows']), 0, 1);
        $pdf->Ln(2);
        $sum = array_sum($t['widths']);
        $w = array_map(fn ($x) => $x / $sum * 281, $t['widths']);
        $head = function () use ($pdf, $t, $w) {
            $pdf->SetFont('DejaVu', 'B', 7.5);
            $pdf->SetFillColor(31, 100, 112);
            $pdf->SetTextColor(255, 255, 255);
            foreach ($t['cols'] as $i => $c) {
                $pdf->Cell($w[$i], 6, self::fit($pdf, $c, $w[$i]), 0, 0, 'L', true);
            }
            $pdf->Ln();
            $pdf->SetFont('DejaVu', '', 7.5);
            $pdf->SetTextColor(25, 35, 40);
        };
        $head();
        foreach ($t['rows'] as $ri => $row) {
            // строка может быть в несколько линий: считаем высоту по самой длинной ячейке
            $lines = [];
            foreach (array_values($row) as $i => $v) {
                $lines[$i] = self::wrap($pdf, (string) ($v ?? ''), $w[$i] - 1.5, 4);
            }
            $h = 4.2 * max(1, ...array_map('count', $lines)) + 1.2;
            if ($pdf->GetY() + $h > $pdf->GetPageHeight() - 12) {
                $pdf->AddPage();
                $head();
            }
            $y = $pdf->GetY();
            $x = 8;
            if ($ri % 2) {
                $pdf->SetFillColor(242, 247, 248);
                $pdf->Rect(8, $y, 281, $h, 'F');
            }
            foreach ($lines as $i => $ls) {
                foreach ($ls as $k => $line) {
                    $pdf->SetXY($x + 0.6, $y + 0.6 + $k * 4.2);
                    $pdf->Cell($w[$i] - 1.2, 4.2, $line);
                }
                $x += $w[$i];
            }
            $pdf->SetXY(8, $y + $h);
        }
        if (!$t['rows']) {
            $pdf->Cell(0, 8, 'Пока нет записей.', 0, 1);
        }
        $pdf->SetY(-9);
        $pdf->SetFont('DejaVu', '', 7);
        $pdf->SetTextColor(130, 140, 145);
        $pdf->Cell(0, 4, 'smart.is-best.net · стр. ' . $pdf->PageNo() . ' из {nb}', 0, 0, 'R');

        return $pdf->Output('S');
    }

    /** Перенос текста по ширине ячейки, не больше $max строк (последняя — с «…»). */
    private static function wrap(\tFPDF $pdf, string $s, float $width, int $max): array
    {
        $s = trim(preg_replace('~\s+~u', ' ', $s));
        if ($s === '' || $pdf->GetStringWidth($s) <= $width) {
            return [$s];
        }
        $out = [];
        $line = '';
        foreach (explode(' ', $s) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($pdf->GetStringWidth($try) <= $width) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $out[] = $line;
            }
            $line = self::fit($pdf, $word, $width + 1.5);
            if (count($out) === $max - 1) {
                break;
            }
        }
        if (count($out) < $max) {
            $out[] = $line;
        }
        if (count($out) === $max && implode(' ', $out) !== $s) {
            $out[$max - 1] = self::fit($pdf, $out[$max - 1] . ' …', $width + 1.5);
        }

        return $out;
    }

    private static function fit(\tFPDF $pdf, string $s, float $width): string
    {
        $width -= 1.5;
        if ($pdf->GetStringWidth($s) <= $width) {
            return $s;
        }
        while ($s !== '' && $pdf->GetStringWidth($s . '…') > $width) {
            $s = mb_substr($s, 0, -1);
        }

        return $s . '…';
    }

    // ---------- полный архив ----------

    /** Путь к временному ZIP со всеми данными. Вызывающий удаляет файл после отправки. */
    public function archive(): string
    {
        $app = $this->app;
        $stamp = date('Y-m-d');
        $tmp = tempnam(sys_get_temp_dir(), 'swzip');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $leads = $this->leads();
        $results = $this->results();
        $z->addFromString("smartway-{$stamp}.xlsx", $this->xlsx([$leads, $results]));
        $z->addFromString("zayavki-{$stamp}.csv", $this->csv($leads));
        $z->addFromString("rezultaty-{$stamp}.csv", $this->csv($results));

        $db = $app->leads->db();
        $attempts = $db->query('SELECT * FROM diag_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attempts as &$a) {
            $a['answers'] = json_decode((string) $a['answers'], true);
            $a['topics'] = json_decode((string) $a['topics'], true);
        }
        unset($a);
        $z->addFromString('data.json', json_encode([
            'exported_at' => date('c'),
            'site' => 'smart.is-best.net',
            'leads' => $db->query('SELECT * FROM leads ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'diag_attempts' => $attempts,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // база целиком (для восстановления как есть) и правки сайта из админки
        $copy = $app->storage . '/cache/export-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $db->exec('VACUUM INTO ' . $db->quote($copy));
        } catch (\Throwable) {
            @copy($app->storage . '/leads.sqlite', $copy);
        }
        if (is_file($copy)) {
            $z->addFile($copy, 'storage/leads.sqlite');
        }
        foreach (glob($app->storage . '/*.json') ?: [] as $f) {
            if (!in_array(basename($f), ['admin.json', 'throttle.json'], true)) { // пароль и счётчик входов не выгружаем
                $z->addFile($f, 'storage/' . basename($f));
            }
        }
        foreach (glob($app->public . '/uploads/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $f) {
            $z->addFile($f, 'uploads/' . basename($f));
        }
        $z->addFromString('README.txt', implode("\r\n", [
            'SMART WAY — полная выгрузка данных сайта от ' . date('d.m.Y H:i'),
            '',
            "smartway-{$stamp}.xlsx  — заявки и результаты тестов (Excel, два листа)",
            'zayavki-*.csv, rezultaty-*.csv — то же в CSV',
            'data.json — все заявки и прохождения тестов с ответами (для переноса на другой сайт)',
            'storage/leads.sqlite — база заявок и тестов как есть',
            'storage/*.json — правки текстов, цен, контактов и преподавателей из админки',
            'uploads/ — фото, загруженные через админку',
            '',
            'Чтобы восстановить сайт на новом хостинге: залить сайт и положить папку storage в app/storage, а uploads — в public/uploads.',
        ]));
        $z->close();
        @unlink($copy);

        return $tmp;
    }
}
