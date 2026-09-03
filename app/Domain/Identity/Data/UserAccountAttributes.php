<?php

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\UserRole;

/**
 * The fields an administrator may set on an account.
 *
 * A null property means "not supplied", never "set to null". That distinction
 * carries real weight on an edit: an empty password box means leave the password
 * alone, and a role select that the form disabled — as it does on your own
 * account — must not be read as a request to change anything.
 *
 * Only these five fields exist here. `remember_token`, `email_verified_at` and
 * anything session-related are deliberately outside the shape an administrator
 * can reach.
 */
final readonly class UserAccountAttributes
{
    private function __construct(
        public ?string $name,
        public ?string $email,
        public ?UserRole $role,
        public ?bool $isActive,
        public ?string $password,
    ) {}

    /**
     * Build from submitted form data.
     *
     * Whitespace is trimmed and the address is lower-cased here, once, so every
     * later comparison — uniqueness, "did this actually change", the audit
     * label — is made against the same normalized value.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFormData(array $data): self
    {
        return new self(
            name: self::text($data, 'name'),
            email: self::email($data, 'email'),
            role: self::role($data),
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            // A blank box is not a password, it is the absence of one.
            password: self::secret($data, 'password'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || ! is_string($data[$key])) {
            return null;
        }

        $value = trim($data[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function email(array $data, string $key): ?string
    {
        $value = self::text($data, $key);

        return $value === null ? null : mb_strtolower($value);
    }

    /**
     * The password is taken exactly as typed: trimming it would silently change
     * a credential the person chose.
     *
     * @param  array<string, mixed>  $data
     */
    private static function secret(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || ! is_string($data[$key]) || $data[$key] === '') {
            return null;
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function role(array $data): ?UserRole
    {
        $value = $data['role'] ?? null;

        if ($value instanceof UserRole) {
            return $value;
        }

        return is_string($value) ? UserRole::tryFrom($value) : null;
    }
}
