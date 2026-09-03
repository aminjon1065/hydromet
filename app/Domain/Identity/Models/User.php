<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

/**
 * `session_version` is the account's security stamp: an internal counter that
 * moves whenever the account's sessions must end. It is deliberately absent
 * from `Fillable` — nothing an administrator submits may set it — and listed in
 * `Hidden` so it never leaves the application in a serialized model.
 *
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property int $session_version
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token', 'session_version'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The security stamp starts at 1, matching the column default, so a model
     * that has just been created carries the same value the row does instead of
     * a null that only a reload would fill in.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'session_version' => 1,
    ];

    /**
     * Accounts are deactivated, never deleted.
     *
     * An account is the actor on every audit event it produced, and the audit
     * log is append-only: history whose actor has been erased is worth less
     * than history whose actor is marked inactive. The database enforces the
     * same rule in
     * `2026_09_02_120013_add_user_account_guards`; this half catches the
     * mistake that is easy to write, with a message that says what to do
     * instead.
     */
    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new LogicException(
                'User accounts are never deleted; deactivate the account instead so its audit history keeps its actor.',
            );
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'session_version' => 'integer',
        ];
    }

    /**
     * Administration panel access. Deactivated accounts keep their audit
     * history but lose access immediately.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->canAccessAdminPanel();
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }
}
