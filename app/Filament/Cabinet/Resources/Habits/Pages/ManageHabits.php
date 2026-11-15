<?php

namespace App\Filament\Cabinet\Resources\Habits\Pages;

use App\Filament\Cabinet\Resources\Habits\HabitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHabits extends ManageRecords
{
    protected static string $resource = HabitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(function (array $data) {
                $data['user_id'] = auth()->id();
                $data['sort_order'] = (int) auth()->user()->habits()->max('sort_order') + 1;

                return $data;
            }),
        ];
    }
}
