<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Models\User;
use App\Services\GoalsCsvImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalsCsvImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_export_format_and_skips_repeats(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $csv = "\u{FEFF}Цель;Тип;Важность;Горизонт;Статус;\"Прогресс, %\";Начало;Срок;Выполнено;\"Внутри цели\";Заметки;Выводы;Публично\n"
            ."\"Прочитать книгу\";Знание;Необходимо;\"На месяц\";\"В процессе\";40;2026-11-01;2026-11-30;;;\"глава 1\nглава 2\";;да\n"
            ."Пробежка;Спорт;;;Выполнено;;;2026-11-10;2026-11-10;;;;\n"
            .";;;;;;;;;;;;\n";

        $r = (new GoalsCsvImport)->import($user, $csv);
        $again = (new GoalsCsvImport)->import($user, $csv);

        $this->assertSame([2, 0], [$r['created'], $r['skipped']]);
        $this->assertSame([0, 2], [$again['created'], $again['skipped']]);
        $book = $user->goals()->where('title', 'Прочитать книгу')->first();
        $this->assertSame(Horizon::Month, $book->horizon);
        $this->assertSame(GoalStatus::InProgress, $book->status);
        $this->assertSame("глава 1\nглава 2", $book->notes);
        $this->assertTrue($book->is_public);
        $this->assertSame('2026-11-10', $user->goals()->where('title', 'Пробежка')->first()->completed_at->toDateString());
    }

    public function test_requires_goal_column(): void
    {
        $user = User::factory()->create();
        $this->assertNotEmpty((new GoalsCsvImport)->import($user, "Название;Срок\nX;2026-01-01\n")['errors']);
    }
}
