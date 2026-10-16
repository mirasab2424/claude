<?php

namespace Smart;

/**
 * Форма редактирования, построенная по самим данным: поле {"ru","kz","en"} → три поля языков,
 * список → карточки с «добавить / вверх / вниз / удалить», путь к картинке → превью и загрузка файла.
 * Normalize() приводит присланное к форме исходных данных (лишние ключи отбрасываются).
 */
final class Form
{
    public const LABELS = [
        'site' => 'Контакты и общее', 'name' => 'Название / имя', 'full_name' => 'Полное название', 'city' => 'Город',
        'base_url' => 'Адрес сайта', 'phone' => 'Телефон', 'whatsapp' => 'WhatsApp (только цифры, с 7)', 'instagram' => 'Ссылка на Instagram',
        'instagram_handle' => 'Instagram (как показывать)', 'students' => 'Число учеников', 'rating' => 'Рейтинг', 'ratings_count' => 'Число оценок',
        'family_discount' => 'Семейная скидка', 'slogan' => 'Слоган', 'branches' => 'Филиалы', 'address' => 'Адрес', 'note' => 'Примечание',
        'district' => 'Район', 'hours' => 'Часы работы', 'ratings' => 'Оценки', 'reviews' => 'Отзывы', 'gis' => 'Ссылка на 2ГИС',
        'control' => 'Контроль обучения', 'when' => 'Когда', 'title' => 'Заголовок', 'text' => 'Текст', 'stats' => 'Цифры', 'value' => 'Значение',
        'label' => 'Подпись', 'courses' => 'Курсы', 'slug' => 'Адрес страницы (не менять)', 'color' => 'Цвет (teal, pink, sun, violet, green, orange)',
        'from' => 'Цена «от»', 'per_month' => 'Цена за месяц', 'img' => 'Картинка', 'short' => 'Короткое название', 'tag' => 'Метка', 'lead' => 'Описание',
        'languages_extra' => 'Другие языки (сноска)', 'english' => 'Английский', 'modules' => 'Модули', 'why' => 'Почему мы', 'group' => 'Группа: цены',
        'freq' => 'Частота / формат', 'price' => 'Цена, ₸', 'individual' => 'Индивидуально: цены', 'group_includes' => 'Группа: что входит',
        'individual_includes' => 'Индивидуально: что входит', 'intensive' => 'Интенсив с носителями', 'books' => 'Книги',
        'ielts' => 'IELTS', 'features' => 'Преимущества', 'prices' => 'Цены', 'results' => 'Результаты', 'math' => 'Математика', 'steps' => 'Шаги',
        'includes' => 'Что входит', 'combo_geometry' => 'Комбо с геометрией: что входит', 'combo_physics' => 'Комбо с физикой: что входит',
        'combo_prices' => 'Комбо: цены', 'combo_result' => 'Комбо: результат', 'nis' => 'НИШ', 'why_nis' => 'Почему НИШ',
        'subjects_5' => 'Предметы 5 класса', 'subjects_6' => 'Предметы 6 класса', 'subject' => 'Предмет', 'per_week' => 'Раз в неделю',
        'system' => 'Наша система', 'plans' => 'Тарифы', 'grade' => 'Класс', 'month' => 'В месяц, ₸', 'q3' => '3 месяца, ₸', 'q9' => '9 месяцев, ₸',
        'cash' => 'Об оплате', 'early' => 'Почему начинать заранее', 'faq' => 'Вопросы и ответы', 'q' => 'Вопрос', 'a' => 'Ответ',
        'school' => 'Подготовка к школе', 'program' => 'Программа', 'outcomes' => 'Результат', 'prodlenka' => 'Продлёнка', 'blocks' => 'Блоки',
        'teachers' => 'Преподаватели', 'photo' => 'Фото', 'subjects' => 'Предметы (через запятую)', 'experience' => 'Опыт', 'edu' => 'Образование',
        'about' => 'О преподавателе', 'teacher_standards' => 'Требования к преподавателям', 'photos' => 'Фото из жизни центра', 'caption' => 'Подпись',
        'href' => 'Ссылка', 'version' => 'Версия',
    ];

    private int $tplId = 0;

    public function __construct(private array $courseSlugs = [])
    {
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    private static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function isImage(string $key, mixed $v): bool
    {
        return in_array($key, ['img', 'photo'], true) || (is_string($v) && preg_match('~^(img|uploads)/.+\.(jpe?g|png|webp)$~i', $v));
    }

    /** @param array $path имена уровней, например ['teachers', '0', 'name'] */
    public function field(array $path, mixed $value, mixed $schema, string $key): string
    {
        $name = 'data[' . implode('][', $path) . ']';
        $label = self::label($key);

        if (Content::isLocalized($schema)) {
            $long = max(array_map(fn ($x) => mb_strlen((string) $x), (array) $schema + (is_array($value) ? $value : []))) > 70;
            $h = '<div class="f f--l10n"><span class="f__label">' . self::e($label) . '</span><div class="l10n">';
            foreach (Content::LANGS as $l) {
                $v = is_array($value) ? ($value[$l] ?? '') : '';
                $n = self::e($name . '[' . $l . ']');
                $h .= '<label><i>' . strtoupper($l) . '</i>' . ($long
                    ? '<textarea name="' . $n . '" rows="3">' . self::e($v) . '</textarea>'
                    : '<input name="' . $n . '" value="' . self::e($v) . '">') . '</label>';
            }

            return $h . '</div></div>';
        }

        if (is_array($schema) && array_is_list($schema)) {
            return $this->listField($path, is_array($value) ? $value : [], $schema, $key);
        }

        if (is_array($schema)) {
            $h = '';
            foreach ($schema as $k => $sub) {
                $h .= $this->field([...$path, $k], is_array($value) ? ($value[$k] ?? null) : null, $sub, (string) $k);
            }

            return $h;
        }

        if (is_bool($schema)) {
            return '<label class="f f--check"><input type="hidden" name="' . self::e($name) . '" value="0"><input type="checkbox" name="'
                . self::e($name) . '" value="1"' . ($value ? ' checked' : '') . '> ' . self::e($label) . '</label>';
        }

        if (self::isImage($key, $schema)) {
            $src = (string) $value;

            return '<div class="f f--img"><span class="f__label">' . self::e($label) . '</span>'
                . '<img src="' . ($src !== '' ? '/' . self::e($src) : 'data:,') . '" alt="" data-preview>'
                . '<input name="' . self::e($name) . '" value="' . self::e($src) . '" placeholder="img/… или uploads/…">'
                . '<input type="file" name="up[' . self::e(implode('|', $path)) . ']" accept="image/jpeg,image/png,image/webp"></div>';
        }

        if (is_array($value)) {
            $value = Content::isLocalized($value) ? $value['ru'] : '';
        }
        $v = (string) ($value ?? '');
        $long = mb_strlen($v) > 70 || mb_strlen((string) $schema) > 70;

        return '<label class="f"><span class="f__label">' . self::e($label) . '</span>' . ($long
            ? '<textarea name="' . self::e($name) . '" rows="3">' . self::e($v) . '</textarea>'
            : '<input name="' . self::e($name) . '" value="' . self::e($v) . '">') . '</label>';
    }

    private function listField(array $path, array $items, array $schema, string $key): string
    {
        $proto = $schema[0] ?? '';
        $label = self::label($key);

        // Список курсов у преподавателя — галочки
        if ($key === 'courses' && is_string($proto) && $this->courseSlugs) {
            $name = 'data[' . implode('][', $path) . '][]';
            $h = '<div class="f"><span class="f__label">На страницах курсов</span><div class="checks-row">';
            $h .= '<input type="hidden" name="' . self::e($name) . '" value="">';
            foreach ($this->courseSlugs as $slug => $title) {
                $h .= '<label><input type="checkbox" name="' . self::e($name) . '" value="' . self::e($slug) . '"'
                    . (in_array($slug, $items, true) ? ' checked' : '') . '> ' . self::e($title) . '</label>';
            }

            return $h . '</div></div>';
        }

        $ph = '__N' . (++$this->tplId) . '__';
        $flat = !is_array($proto) || Content::isLocalized($proto);
        $h = '<fieldset class="list' . ($flat ? ' list--flat' : '') . '" data-ph="' . $ph . '"><legend>' . self::e($label) . '</legend><div class="list__items">';
        foreach (array_values($items) as $i => $item) {
            $h .= $this->item([...$path, (string) $i], $item, $proto, $key, $i + 1);
        }
        $h .= '</div><template>' . $this->item([...$path, $ph], self::blank($proto), $proto, $key, 0) . '</template>';

        return $h . '<button type="button" class="btn-add" data-add>+ Добавить</button></fieldset>';
    }

    private function item(array $path, mixed $value, mixed $proto, string $key, int $n): string
    {
        $title = '';
        if (is_array($value)) {
            foreach (['name', 'title', 'q', 'freq', 'grade', 'subject', 'caption', 'when', 'value'] as $k) {
                if (isset($value[$k])) {
                    $title = is_array($value[$k]) ? ($value[$k]['ru'] ?? '') : (string) $value[$k];
                    break;
                }
            }
        }
        $inner = $this->field($path, $value, $proto, is_array($proto) && !Content::isLocalized($proto) ? '' : self::itemLabel($key));

        return '<div class="item"><div class="item__bar"><b>' . ($n ? $n . '. ' : '') . self::e($title) . '</b>'
            . '<span><button type="button" data-up title="Выше">↑</button><button type="button" data-down title="Ниже">↓</button>'
            . '<button type="button" data-del title="Удалить">✕</button></span></div>' . $inner . '</div>';
    }

    private static function itemLabel(string $key): string
    {
        return in_array($key, ['books'], true) ? 'img' : 'Пункт';
    }

    public static function blank(mixed $proto): mixed
    {
        if (Content::isLocalized($proto)) {
            return array_fill_keys(Content::LANGS, '');
        }
        if (is_array($proto)) {
            return array_is_list($proto) ? [] : array_map(fn ($x) => self::blank($x), $proto);
        }

        return is_bool($proto) ? false : '';
    }

    /** Приводит присланные данные к форме образца. */
    public static function normalize(mixed $in, mixed $schema): mixed
    {
        if (Content::isLocalized($schema)) {
            $out = [];
            foreach (Content::LANGS as $l) {
                $out[$l] = trim(str_replace("\r\n", "\n", (string) (is_array($in) ? ($in[$l] ?? '') : '')));
            }

            return $out;
        }
        if (is_array($schema) && array_is_list($schema)) {
            $proto = $schema[0] ?? '';
            $out = [];
            foreach (is_array($in) ? $in : [] as $item) {
                $v = self::normalize($item, $proto);
                if ($v !== '' && $v !== self::normalize(null, $proto)) {
                    $out[] = $v;
                }
            }

            return $out;
        }
        if (is_array($schema)) {
            $out = [];
            foreach ($schema as $k => $sub) {
                $out[$k] = self::normalize(is_array($in) ? ($in[$k] ?? null) : null, $sub);
            }

            return $out;
        }
        if (is_bool($schema)) {
            return (bool) $in;
        }
        if (is_int($schema)) {
            return (int) $in;
        }

        return trim(str_replace("\r\n", "\n", (string) ($in ?? '')));
    }
}
