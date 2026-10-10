<?php

namespace App\Http\Controllers;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Enums\MetricDirection;
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

        $metrics = $stats->metrics()->map(function (Metric $m) {
            $trend = $m->trend();
            $last = $m->entries->last();

            return [
                'id' => $m->id,
                'name' => $m->name,
                'unit' => $m->is_duration ? '' : $m->unit,
                'duration' => $m->is_duration,
                'target' => $m->target,
                'target_text' => $m->target !== null ? $m->format($m->target) : null,
                'trend' => $trend,
                'last_text' => $last ? $m->format($last->value) : null,
                'last_date' => $last?->date->translatedFormat('j M Y'),
                'best_text' => $m->entries->isNotEmpty()
                    ? $m->format($m->direction === MetricDirection::Down ? $m->entries->min('value') : $m->entries->max('value'))
                    : null,
                'delta_text' => $trend ? $m->format($trend['delta'], signed: true) : null,
                'count' => $m->entries->count(),
                'points' => $m->entries->map(fn ($e) => ['x' => $e->date->toDateString(), 'y' => $e->value, 'note' => $e->note])->values(),
            ];
        });

        $gallery = $goals
            ->filter(fn (Goal $g) => $g->photo)
            ->sortByDesc(fn (Goal $g) => $g->completed_at ?? $g->created_at)
            ->values();

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
            'gallery' => $gallery,
            'since' => $goals->map(fn (Goal $g) => $g->completed_at ?? $g->created_at)->filter()->min(),
            'recentDone' => $goals->where('status', GoalStatus::Done)->sortByDesc('completed_at')->take(8)->values(),
            'chartData' => [
                'timeline' => $stats->timeline(),
                'categories' => $stats->byCategory(),
                'horizons' => $stats->byHorizon(),
                'heatmap' => $stats->heatmap(),
                'metrics' => $metrics,
                'places' => $places,
            ],
        ]);
    }
}
