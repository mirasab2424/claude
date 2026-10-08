<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_site_renders(): void
    {
        $this->seed();

        $this->get('/')->assertOk()->assertSee('Miras');
        $this->get('/u/miras')->assertOk()->assertSee('Активность за год');
    }

    public function test_username_is_generated_on_registration(): void
    {
        $a = User::factory()->create(['name' => 'Иван Петров']);
        $b = User::factory()->create(['name' => 'Иван Петров']);

        $this->assertSame('ivan-petrov', $a->username);
        $this->assertSame('ivan-petrov-2', $b->username);
    }

    public function test_private_profile_is_hidden_from_others_but_visible_to_owner(): void
    {
        $owner = User::factory()->create(['is_public' => false]);

        $this->get("/u/{$owner->username}")->assertNotFound();
        $this->actingAs($owner)->get("/u/{$owner->username}")->assertOk()->assertSee('Профиль скрыт');
    }

    public function test_private_goals_are_not_shown_publicly(): void
    {
        $user = User::factory()->create();
        Goal::create(['user_id' => $user->id, 'title' => 'Секретная цель', 'is_public' => false]);
        Goal::create(['user_id' => $user->id, 'title' => 'Открытая цель']);

        $this->get("/u/{$user->username}")->assertOk()->assertSee('Открытая цель')->assertDontSee('Секретная цель');
        $this->actingAs($user)->get("/u/{$user->username}")->assertSee('Секретная цель');
    }

    public function test_cabinet_is_scoped_to_own_records(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Goal::create(['user_id' => $other->id, 'title' => 'Чужая цель']);
        Goal::create(['user_id' => $me->id, 'title' => 'Моя цель']);

        $this->actingAs($me)->get('/cabinet/goals')->assertOk()->assertSee('Моя цель')->assertDontSee('Чужая цель');
        $this->actingAs($me)->get("/cabinet/goals/{$foreign->id}/edit")->assertNotFound();
    }

    public function test_admin_panel_requires_admin_flag(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();

        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_is_admin_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'is_admin' => true]);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_completing_goal_sets_progress_and_timestamp(): void
    {
        $user = User::factory()->create();
        $goal = Goal::create(['user_id' => $user->id, 'title' => 'Цель', 'progress' => 30]);

        $goal->update(['status' => GoalStatus::Done]);
        $this->assertSame(100, $goal->progress);
        $this->assertNotNull($goal->completed_at);

        $goal->update(['status' => GoalStatus::InProgress]);
        $this->assertNull($goal->completed_at);
    }
}
