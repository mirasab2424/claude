<?php

namespace App\Filament\Shared;

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
            TextInput::make('value')->label('Значение')->numeric()->required(),
            TextInput::make('note')->label('Комментарий')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('value')->label('Значение')->numeric(),
                TextColumn::make('note')->label('Комментарий'),
            ])
            ->headerActions([CreateAction::make()->label('Добавить замер')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
