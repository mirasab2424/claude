<?php

namespace App\Filament\Admin\Resources\Goals\Pages;

use App\Filament\Admin\Resources\Goals\GoalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGoal extends CreateRecord
{
    protected static string $resource = GoalResource::class;
}
