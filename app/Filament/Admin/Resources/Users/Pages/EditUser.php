<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->hidden(fn () => $this->record->is(auth()->user())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $isAdmin = (bool) ($data['is_admin'] ?? $record->is_admin);
        unset($data['is_admin']);
        // Не даём админу случайно снять права с самого себя.
        if ($record->is(auth()->user())) {
            $isAdmin = true;
        }
        $record->fill($data)->forceFill(['is_admin' => $isAdmin])->save();

        return $record;
    }
}
