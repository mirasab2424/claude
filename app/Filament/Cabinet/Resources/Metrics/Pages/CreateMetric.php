<?php

namespace App\Filament\Cabinet\Resources\Metrics\Pages;

use App\Filament\Cabinet\Resources\Metrics\MetricResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMetric extends CreateRecord
{
    protected static string $resource = MetricResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
