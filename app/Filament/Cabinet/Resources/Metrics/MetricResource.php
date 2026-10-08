<?php

namespace App\Filament\Cabinet\Resources\Metrics;

use App\Filament\Cabinet\Resources\Metrics\Pages\CreateMetric;
use App\Filament\Cabinet\Resources\Metrics\Pages\EditMetric;
use App\Filament\Cabinet\Resources\Metrics\Pages\ListMetrics;
use App\Filament\Shared\MetricForm;
use App\Filament\Shared\MetricsTable;
use App\Filament\Shared\EntriesRelationManager;
use App\Models\Metric;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MetricResource extends Resource
{
    protected static ?string $model = Metric::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $modelLabel = 'метрику';

    protected static ?string $pluralModelLabel = 'Метрики';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return MetricForm::configure($schema, admin: false);
    }

    public static function table(Table $table): Table
    {
        return MetricsTable::configure($table, admin: false);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetrics::route('/'),
            'create' => CreateMetric::route('/create'),
            'edit' => EditMetric::route('/{record}/edit'),
        ];
    }
}
