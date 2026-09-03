<?php

namespace App\Console\Commands;

use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\UserAccountRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserAccountManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the very first administrator, once, on an installation that has none.
 *
 * There is no seeded account and no default administrator: a shared, known
 * password must never exist in the repository or in any environment. That
 * leaves the first account to be created deliberately on the server, and this
 * command is that step — narrow enough to be safe and specific enough to be
 * repeatable, which `make:filament-user` (which creates an editor, and keeps
 * creating them) was not.
 *
 * It refuses the moment `users` holds a single row, so it can only ever run
 * once. Every account after the first is created by an administrator through
 * Filament, which is where the rules that protect the panel live.
 */
class BootstrapAdministrator extends Command
{
    protected $signature = 'users:bootstrap-administrator';

    protected $description = 'Create the first administrator on an installation whose user table is still empty';

    public function handle(UserAccountManager $accounts): int
    {
        if (User::query()->exists()) {
            $this->components->error((string) __('identity.bootstrap.not_empty'));
            $this->line((string) __('identity.bootstrap.not_empty_hint'));

            return self::FAILURE;
        }

        $this->line((string) __('identity.bootstrap.intro'));

        $submitted = [
            'name' => $this->ask((string) __('identity.fields.name')),
            'email' => $this->ask((string) __('identity.fields.email')),
            // Read hidden, never echoed, never logged, and deliberately not
            // available as a command option: an option would put the password
            // into the shell history and into the process list, where every
            // other user on the host can read it.
            'password' => $this->secret((string) __('identity.fields.password')),
            'password_confirmation' => $this->secret((string) __('identity.fields.password_confirmation')),
        ];

        // Normalized before validation, exactly as the panel form normalizes
        // it, so the address that is checked for shape is the address that gets
        // stored — trimmed, and in lower case.
        $attributes = UserAccountAttributes::fromFormData([
            'name' => $submitted['name'],
            'email' => $submitted['email'],
            'role' => UserRole::Administrator->value,
            'is_active' => true,
            'password' => $submitted['password'],
        ]);

        $validator = Validator::make([
            'name' => $attributes->name,
            'email' => $attributes->email,
            'password' => $attributes->password,
            'password_confirmation' => $submitted['password_confirmation'],
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // The same policy the Filament form applies, taken from the same
            // default so the two cannot drift apart.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [], [
            'name' => (string) __('identity.fields.name'),
            'email' => (string) __('identity.fields.email'),
            'password' => (string) __('identity.fields.password'),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        try {
            $account = $accounts->bootstrapFirstAdministrator($attributes);
        } catch (UserAccountRuleViolation $violation) {
            foreach ($violation->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->components->info((string) __('identity.bootstrap.created', ['email' => $account->email]));
        $this->line((string) __('identity.bootstrap.next_steps'));

        return self::SUCCESS;
    }
}
