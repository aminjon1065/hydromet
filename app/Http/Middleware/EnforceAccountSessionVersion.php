<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a panel session whose account has moved on without it.
 *
 * Deactivating an account, changing its role or changing its password must take
 * effect on the person's next page, not on their next sign-in. Deleting rows
 * from the `sessions` table only achieves that when sessions are stored in
 * rows: the portal's own `.env.example` selects Redis, where they are not, and
 * finding one account's sessions there would mean scanning the keyspace — which
 * this portal will not do, and which would still be a scan of shared
 * infrastructure rather than a control.
 *
 * So the account carries a version instead
 * (`App\Domain\Identity\Services\UserAccountManager`). The first authenticated
 * request stamps the session with the version the account has; every request
 * after that compares the stamp against the stored column. A session opened
 * before a change carries the older number and is signed out. Nothing about the
 * session store is assumed, so this behaves identically on Redis, the database,
 * files and the array driver.
 *
 * Registered in the panel's authenticated middleware, after
 * `Filament\Http\Middleware\Authenticate`, because it needs a resolved user to
 * have anything to compare. Filament's `AuthenticateSession` stays where it is
 * in the panel stack: it covers a changed password hash on its own, and this
 * covers the two cases it cannot see — a deactivation and a role change.
 */
final class EnforceAccountSessionVersion
{
    /**
     * The stamp: which account this session belongs to, and the version that
     * account had when it was stamped.
     *
     * The account id is part of it because signing in migrates the session's
     * data onto the new session id. Without it, a stamp left by whoever used
     * this browser before would be compared against the version of whoever
     * signed in after them, and sign them straight back out.
     *
     * Nothing credential-shaped is stored: two integers, neither of which can
     * authenticate as anybody.
     */
    public const SESSION_KEY = 'identity.account_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $accountId = (int) $account->getKey();
        $current = $this->storedVersion($accountId);

        if ($current === null) {
            // The account is gone. Accounts are never deleted, so this cannot
            // happen through any supported path — and if it somehow has, the
            // safe reading of "I cannot tell whether this session is still
            // valid" is that it is not.
            $this->endSession($session);
        }

        $stamp = $session->get(self::SESSION_KEY);

        if (! $this->stampBelongsTo($stamp, $accountId)) {
            $session->put(self::SESSION_KEY, ['account' => $accountId, 'version' => $current]);

            return $next($request);
        }

        if ($stamp['version'] === $current) {
            return $next($request);
        }

        $this->endSession($session);
    }

    /**
     * Whether the stamp in the session was written for this account and can be
     * compared. Anything else — absent, malformed, or another account's — is
     * treated as "not stamped yet" and re-stamped rather than trusted.
     *
     * @phpstan-assert-if-true array{account: int, version: int} $stamp
     */
    private function stampBelongsTo(mixed $stamp, int $accountId): bool
    {
        return is_array($stamp)
            && isset($stamp['account'], $stamp['version'])
            && is_int($stamp['account'])
            && is_int($stamp['version'])
            && $stamp['account'] === $accountId;
    }

    /**
     * The account's version as the database holds it now, rather than as the
     * guard happens to have it in memory.
     */
    private function storedVersion(int $accountId): ?int
    {
        $version = User::query()->whereKey($accountId)->value('session_version');

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * Sign this device out and send the person to the panel login.
     *
     * `logoutCurrentDevice` rather than `logout` on the session guard: the
     * account's other sessions are already covered — each carries the same
     * stale stamp and is refused on its own next request — and cycling the
     * remember token here would reach devices the administrator did not touch.
     * Only `SessionGuard` draws that distinction; any other stateful guard gets
     * the plain logout, which is the safe side of the difference.
     *
     * @throws AuthenticationException
     */
    private function endSession(Session $session): never
    {
        $guard = Filament::auth();

        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        } elseif ($guard instanceof StatefulGuard) {
            $guard->logout();
        }

        $session->invalidate();
        $session->regenerateToken();

        throw new AuthenticationException(
            __('identity.errors.session_ended'),
            [Filament::getAuthGuard()],
            Filament::getLoginUrl(),
        );
    }
}
