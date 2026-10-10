<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PostDeploy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(base_path('deploy-version'));
        @unlink(storage_path('framework/deployed-version'));
        @unlink(storage_path('framework/telegram-imported'));
        parent::tearDown();
    }

    public function test_new_version_imports_diary_once(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        file_put_contents(base_path('deploy-version'), 'abc123');

        PostDeploy::runIfNeeded();

        $this->assertSame('abc123', trim(file_get_contents(storage_path('framework/deployed-version'))));
        $this->assertSame(88, $admin->goals()->count());

        // Пользователь удалил всё сам — следующий деплой ничего не возвращает.
        $admin->goals()->delete();
        file_put_contents(base_path('deploy-version'), 'def456');
        PostDeploy::runIfNeeded();
        $this->assertSame(0, $admin->goals()->count());
    }

    public function test_nothing_happens_without_version_file(): void
    {
        PostDeploy::runIfNeeded();

        $this->assertFileDoesNotExist(storage_path('framework/deployed-version'));
    }
}
