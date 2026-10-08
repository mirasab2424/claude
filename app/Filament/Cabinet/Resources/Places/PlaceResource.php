<?php

namespace App\Filament\Cabinet\Resources\Places;

use App\Filament\Cabinet\Resources\Places\Pages\CreatePlace;
use App\Filament\Cabinet\Resources\Places\Pages\EditPlace;
use App\Filament\Cabinet\Resources\Places\Pages\ListPlaces;
use App\Filament\Shared\PlaceForm;
use App\Filament\Shared\PlacesTable;
use App\Models\Place;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaceResource extends Resource
{
    protected static ?string $model = Place::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $modelLabel = 'место';

    protected static ?string $pluralModelLabel = 'Карта мест';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return PlaceForm::configure($schema, admin: false);
    }

    public static function table(Table $table): Table
    {
        return PlacesTable::configure($table, admin: false);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaces::route('/'),
            'create' => CreatePlace::route('/create'),
            'edit' => EditPlace::route('/{record}/edit'),
        ];
    }
}
