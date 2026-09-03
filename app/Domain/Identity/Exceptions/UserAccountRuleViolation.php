<?php

namespace App\Domain\Identity\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A user-account change the portal refuses on its own rules.
 *
 * Extends `ValidationException` so the refusal reaches an administrator as a
 * message against the field they touched, rather than as an error page: these
 * are decisions ("you cannot remove the last administrator"), not faults, and
 * showing a stack trace for one would be both alarming and useless.
 *
 * The messages are translated at the call site, because the person reading them
 * is an operator using the panel in their own language.
 */
final class UserAccountRuleViolation extends ValidationException
{
    /**
     * Removing the account that keeps the panel reachable.
     */
    public static function lastActiveAdministrator(string $field): self
    {
        return self::withMessages([$field => __('identity.errors.last_active_administrator')]);
    }

    /**
     * An administrator locking themselves out. The rule exists separately from
     * the last-administrator one: with two administrators the portal survives,
     * but the person clicking still loses their own access mid-session.
     */
    public static function selfDeactivation(): self
    {
        return self::withMessages(['is_active' => __('identity.errors.self_deactivation')]);
    }

    public static function selfRoleChange(): self
    {
        return self::withMessages(['role' => __('identity.errors.self_role_change')]);
    }

    public static function emailAlreadyTaken(): self
    {
        return self::withMessages(['email' => __('identity.errors.email_taken')]);
    }

    /**
     * The bootstrap command asked to create the first administrator on an
     * installation that already has accounts. It creates exactly one, once;
     * after that, accounts are added by an administrator through the panel.
     */
    public static function installationAlreadyHasAccounts(): self
    {
        return self::withMessages(['email' => __('identity.errors.installation_not_empty')]);
    }
}
