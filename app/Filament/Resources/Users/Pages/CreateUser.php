<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Services\UserAccountManager;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The page hands the submitted fields to the domain service and does
     * nothing else.
     *
     * Creating the model here instead would put normalisation, uniqueness,
     * hashing and the audit write in a page class, where the next caller —
     * a console command, a seeder, a second panel — would not find them.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(UserAccountManager::class)->create(
            UserAccountAttributes::fromFormData($data),
            $this->getCurrentUser(),
        );
    }

    private function getCurrentUser(): ?Authenticatable
    {
        return auth()->user();
    }
}
