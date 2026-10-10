<?php

namespace App\Filament\Shared;

use App\Models\Metric;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Замеры';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')->label('Дата')->default(now())->required(),
            TextInput::make('value')
                ->label(fn () => $this->getOwnerRecord()->is_duration ? 'Время (мм:сс)' : 'Значение')
                ->placeholder(fn () => $this->getOwnerRecord()->is_duration ? '13:52' : null)
                ->required()
                ->regex('/^\d+([.,:]\d+)*$/')
                ->formatStateUsing(fn ($state) => $state !== null && $this->getOwnerRecord()->is_duration ? $this->getOwnerRecord()->format((float) $state) : $state)
                ->dehydrateStateUsing(fn ($state) => $this->getOwnerRecord()->is_duration ? Metric::parseDuration($state) : (float) str_replace(',', '.', $state)),
            TextInput::make('note')->label('Комментарий')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('value')->label('Значение')->formatStateUsing(fn ($state) => $this->getOwnerRecord()->format((float) $state)),
                TextColumn::make('note')->label('Комментарий'),
            ])
            ->headerActions([CreateAction::make()->label('Добавить замер')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
