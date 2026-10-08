<?php

namespace App\Http\Controllers;

use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\Place;
use App\Models\User;
use App\Services\UserStats;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $members = User::public()
            ->withCount(['goals as public_goals_count' => fn ($q) => $q->public()])
            ->orderByDesc('public_goals_count')
            ->limit(12)
            ->get()
            ->map(fn (User $user) => [
                'user' => $user,
                'summary' => UserStats::for($user)->summary(),
            ]);

        return view('home', [
            'members' => $members,
            'totals' => [
                'users' => User::count(),
                'goals' => Goal::public()->count(),
                'done' => Goal::public()->where('status', GoalStatus::Done)->count(),
                'places' => Place::public()->count(),
            ],
        ]);
    }
}
