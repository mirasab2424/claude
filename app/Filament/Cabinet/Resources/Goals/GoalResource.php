<?php

namespace App\Filament\Cabinet\Resources\Goals;

use App\Filament\Cabinet\Resources\Goals\Pages\CreateGoal;
use App\Filament\Cabinet\Resources\Goals\Pages\EditGoal;
use App\Filament\Cabinet\Resources\Goals\Pages\ListGoals;
use App\Filament\Shared\GoalForm;
use App\Filament\Shared\GoalsTable;
use App\Models\Goal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GoalResource extends Resource
{
    protected static ?string $model = Goal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $modelLabel = 'цель';

    protected static ?string $pluralModelLabel = 'Цели и задачи';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return GoalForm::configure($schema, admin: false);
    }

    public static function table(Table $table): Table
    {
        return GoalsTable::configure($table, admin: false);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoals::route('/'),
            'create' => CreateGoal::route('/create'),
            'edit' => EditGoal::route('/{record}/edit'),
        ];
    }
}
