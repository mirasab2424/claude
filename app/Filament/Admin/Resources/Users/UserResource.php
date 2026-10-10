<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'пользователя';

    protected static ?string $pluralModelLabel = 'Пользователи';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Аккаунт')->columns(2)->schema([
                TextInput::make('name')->label('Имя')->required(),
                TextInput::make('username')
                    ->label('Адрес страницы')
                    ->prefix('/u/')
                    ->alphaDash()
                    ->unique(ignoreRecord: true),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state)),
                Toggle::make('is_admin')->label('Администратор'),
                Toggle::make('is_public')->label('Публичный профиль')->default(true),
            ]),
            Section::make('Профиль')->columns(2)->schema([
                FileUpload::make('avatar')->label('Аватар')->image()->avatar()->disk('public')->directory('avatars'),
                TextInput::make('city')->label('Город'),
                TextInput::make('telegram')->label('Telegram-канал')->url(),
                TextInput::make('tagline')->label('Девиз')->columnSpanFull(),
                Textarea::make('bio')->label('О себе')->rows(4)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')->label('')->disk('public')->circular(),
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('username')->label('Страница')->prefix('/u/')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('goals_count')->label('Целей')->counts('goals')->sortable(),
                IconColumn::make('is_admin')->label('Админ')->boolean(),
                IconColumn::make('is_public')->label('Публичный')->boolean(),
                TextColumn::make('created_at')->label('Регистрация')->date('d.m.Y')->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Страница')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (User $record) => route('profile.show', $record), shouldOpenInNewTab: true),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
