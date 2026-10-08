<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    // is_admin не входит в $fillable, чтобы его нельзя было выставить себе при регистрации.
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $isAdmin = (bool) ($data['is_admin'] ?? false);
        unset($data['is_admin']);
        $user = static::getModel()::create($data);
        $user->forceFill(['is_admin' => $isAdmin])->save();

        return $user;
    }
}
