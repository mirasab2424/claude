<?php

namespace App\Filament\Cabinet\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Публичная страница')
                ->description('Так вас увидят другие на сайте')
                ->schema([
                    FileUpload::make('avatar')->label('Аватар')->image()->avatar()->disk('public')->directory('avatars'),
                    $this->getNameFormComponent(),
                    TextInput::make('username')
                        ->label('Адрес страницы')
                        ->prefix(url('/u').'/')
                        ->required()
                        ->alphaDash()
                        ->maxLength(40)
                        ->unique(ignoreRecord: true),
                    TextInput::make('tagline')->label('Девиз')->maxLength(120),
                    TextInput::make('city')->label('Город')->maxLength(80),
                    TextInput::make('telegram')->label('Telegram-канал')->url()->placeholder('https://t.me/...')->maxLength(255),
                    Textarea::make('bio')->label('О себе')->rows(4),
                    Toggle::make('is_public')
                        ->label('Профиль виден всем')
                        ->helperText('Если выключить, страница будет доступна только вам'),
                ]),
            Section::make('Вход и безопасность')->schema([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]),
        ]);
    }
}
