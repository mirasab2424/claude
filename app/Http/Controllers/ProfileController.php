<?php

namespace App\Http\Controllers;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Models\Goal;
use App\Models\Metric;
use App\Models\Place;
use App\Models\User;
use App\Services\UserStats;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(User $user): View
    {
        $isOwner = auth()->id() === $user->id;
        abort_unless($user->is_public || $isOwner, 404);

        // Владелец видит на своей странице и приватные записи.
        $stats = UserStats::for($user, publicOnly: ! $isOwner);
        $goals = $stats->goals();

        $board = collect(Horizon::cases())->mapWithKeys(fn (Horizon $h) => [
            $h->value => [
                'label' => $h->getLabel(),
                'goals' => $goals
                    ->where('horizon', $h)
                    ->reject(fn (Goal $g) => $g->status->isClosed())
                    ->sortByDesc(fn (Goal $g) => $g->importance->weight())
                    ->values(),
            ],
        ]);

        $lessons = $goals
            ->filter(fn (Goal $g) => $g->status->isClosed() && filled($g->retrospective))
            ->sortByDesc('completed_at')
            ->take(6)
            ->values();

        $metrics = $stats->metrics()->map(fn (Metric $m) => [
            'id' => $m->id,
            'name' => $m->name,
            'unit' => $m->unit,
            'target' => $m->target,
            'trend' => $m->trend(),
            'points' => $m->entries->map(fn ($e) => ['x' => $e->date->toDateString(), 'y' => $e->value])->values(),
        ]);

        $places = $stats->places()->map(fn (Place $p) => [
            'title' => $p->title,
            'lat' => $p->latitude,
            'lng' => $p->longitude,
            'date' => $p->visited_at?->translatedFormat('j F Y'),
            'rating' => $p->rating,
            'description' => $p->description,
            'photo' => $p->photo ? Storage::disk('public')->url($p->photo) : null,
            'category' => $p->category?->label(),
            'color' => $p->category?->color ?? '#22c55e',
        ]);

        return view('profile', [
            'user' => $user,
            'isOwner' => $isOwner,
            'summary' => $stats->summary(),
            'board' => $board,
            'lessons' => $lessons,
            'recentDone' => $goals->where('status', GoalStatus::Done)->sortByDesc('completed_at')->take(8)->values(),
            'chartData' => [
                'timeline' => $stats->timeline(12),
                'categories' => $stats->byCategory(),
                'horizons' => $stats->byHorizon(),
                'heatmap' => $stats->heatmap(53 * 7),
                'metrics' => $metrics,
                'places' => $places,
            ],
        ]);
    }
}
