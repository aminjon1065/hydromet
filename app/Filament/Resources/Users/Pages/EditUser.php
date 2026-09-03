<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserAccountManager;
use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditUser extends EditRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = UserResource::class;

    /**
     * Nothing credential-shaped is ever put into the form.
     *
     * Filament fills the form from the record's attributes, and the record has
     * a password hash on it. Stripping the keys here means the boxes start
     * empty and stay empty unless someone types a new password — which is also
     * how "leave the password alone" is expressed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset(
            $data['password'],
            $data['password_confirmation'],
            $data['remember_token'],
            $data['email_verified_at'],
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User) {
            throw new LogicException('The account edit page received an unexpected model.');
        }

        return app(UserAccountManager::class)->update(
            $record,
            UserAccountAttributes::fromFormData($data),
            auth()->user(),
        );
    }

    /**
     * No delete action. An account is deactivated through the toggle on the
     * form, which keeps its audit history readable.
     *
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
