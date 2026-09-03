<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserAccountManager;
use App\Http\Middleware\EnforceAccountSessionVersion;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A change of access ends the sessions that account already has — proved by
 * opening pages, not by counting rows.
 *
 * The rows in the `sessions` table were never the mechanism. `.env.example`
 * selects the Redis session driver, where those rows do not exist, so a test
 * that inserted one and watched it disappear demonstrated something the
 * production configuration never does. What has to hold is what a person sees:
 * they are signed in, an administrator changes their access, and the next page
 * they open sends them to the login screen.
 *
 * So every test here goes through the panel. The account carries a security
 * stamp, each session records the stamp it was opened against, and
 * `EnforceAccountSessionVersion` compares the two on every authenticated
 * request — which is why the same assertions hold on Redis, the database, files
 * and the array driver alike, and why the driver is a data provider below.
 */
class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    private UserAccountManager $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = app(UserAccountManager::class);
    }

    // --- The stamp ---------------------------------------------------------

    #[Test]
    public function the_first_authenticated_request_stamps_the_session(): void
    {
        $operator = $this->account(UserRole::Operator);

        $this->browse($this->signIn($operator), '/admin')->assertOk();

        $this->assertSame(
            ['account' => $operator->getKey(), 'version' => 1],
            session(EnforceAccountSessionVersion::SESSION_KEY),
        );
    }

    /**
     * The stamp is two integers. Neither can authenticate as anybody, which is
     * the whole reason the session does not simply carry a credential.
     */
    #[Test]
    public function the_stamp_holds_nothing_that_could_sign_anybody_in(): void
    {
        $operator = $this->account(UserRole::Operator);
        $storedHash = $operator->password;

        $this->browse($this->signIn($operator), '/admin')->assertOk();

        $stamp = session(EnforceAccountSessionVersion::SESSION_KEY);

        $this->assertIsArray($stamp);
        $this->assertSame(['account', 'version'], array_keys($stamp));
        $this->assertIsInt($stamp['version']);

        $encoded = json_encode($stamp);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($storedHash, $encoded);
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString('$2y$', $encoded);
    }

    // --- What ends a session -----------------------------------------------

    /**
     * The dashboard is open to every role, so a redirect to the login screen
     * here can only be a sign-out: an ordinary 403 would be indistinguishable
     * from "this page was never yours".
     */
    #[Test]
    public function a_role_change_ends_the_previous_session(): void
    {
        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);

        // Signed in and working.
        $session = $this->signIn($operator);
        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['role' => UserRole::Editor->value], $administrator);

        // An editor may open the dashboard too. This is the sign-out.
        $this->browse($session, '/admin')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    /**
     * `AuthenticateSession` would also catch a changed password, through the
     * hash it keeps in the session. It is switched off here so that what is
     * asserted is this portal's own revocation — the one that covers a
     * deactivation and a role change as well, on every session driver.
     */
    #[Test]
    public function a_password_change_ends_the_previous_session(): void
    {
        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);

        $this->withoutMiddleware(AuthenticateSession::class);

        $session = $this->signIn($operator);
        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['password' => 'a-brand-new-secret-42'], $administrator);

        $this->browse($session, '/admin')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    /**
     * A deactivated account is refused by the panel itself, which proves
     * nothing about sessions. Reactivating it separates the two: the account
     * may enter again, the old session may not, because the stamp moved when it
     * was deactivated and reactivation does not move it back.
     */
    #[Test]
    public function a_deactivation_ends_the_previous_session(): void
    {
        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);

        $session = $this->signIn($operator);
        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['is_active' => false], $administrator);

        // While deactivated, the panel refuses them outright.
        $this->browse($session, '/admin')->assertForbidden();

        $this->change($operator, ['is_active' => true], $administrator);

        // Allowed back in — but not on the session they had before.
        $this->browse($session, '/admin')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    #[Test]
    public function a_rename_leaves_the_session_alone(): void
    {
        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);

        $session = $this->signIn($operator);
        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['name' => 'Renamed Person'], $administrator);

        $this->browse($session, '/admin')->assertOk();
    }

    #[Test]
    public function a_new_email_address_leaves_the_session_alone(): void
    {
        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);

        $session = $this->signIn($operator);
        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['email' => 'moved.address@example.tj'], $administrator);

        $this->browse($session, '/admin')->assertOk();
    }

    /**
     * Only the account that was changed. An administrator demoting one person
     * must not sign out everybody else.
     */
    #[Test]
    public function a_bystander_keeps_working(): void
    {
        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);
        $bystander = $this->account(UserRole::Editor, 'bystander@example.tj');

        $session = $this->signIn($bystander);
        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['role' => UserRole::Editor->value], $administrator);

        $this->browse($session, '/admin')->assertOk();
    }

    // --- Driver independence -----------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function sessionDrivers(): array
    {
        // Redis is the production default and is deliberately absent: it is the
        // driver with no rows to delete and a keyspace that must not be
        // scanned, which is exactly why nothing here touches the session store
        // at all. These three cover in-memory, file and row storage; there is
        // one code path and all four take it.
        return [
            'array' => ['array'],
            'file' => ['file'],
            'database' => ['database'],
        ];
    }

    /**
     * The same sign-out on three real session stores, through a real sign-in:
     * the guard writes the login into the session, the session is written to
     * its driver, and every later request reaches it only through the cookie
     * that names it. No user is placed on the guard by hand, so a request is
     * authenticated here only because the stored session still says so.
     */
    #[Test]
    #[DataProvider('sessionDrivers')]
    public function the_sign_out_does_not_depend_on_the_session_driver(string $driver): void
    {
        $this->useSessionDriver($driver);

        $administrator = $this->administrator();
        $operator = $this->account(UserRole::Operator);

        $session = $this->signIn($operator);

        $this->browse($session, '/admin')->assertOk();

        $this->change($operator, ['role' => UserRole::Editor->value], $administrator);

        $this->browse($session, '/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * Point the session at a real driver for the rest of the test.
     *
     * Three cached things have to go with the old driver: the manager's driver,
     * the container's `session.store` (a singleton resolved from it) and the
     * guards, which were handed that store when they were built.
     */
    private function useSessionDriver(string $driver): void
    {
        if ($driver === 'file') {
            $path = storage_path('framework/testing/sessions');
            File::ensureDirectoryExists($path);
            config(['session.files' => $path]);
        }

        config(['session.driver' => $driver]);

        app(SessionManager::class)->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();

        $this->flushSession();
    }

    /**
     * A real sign-in, and the id of the session it produced.
     */
    private function signIn(User $account): string
    {
        $this->flushSession();

        // Writes the login into the session and migrates it to a fresh id,
        // which is why the id is read afterwards.
        Auth::guard()->login($account);

        $session = session();
        $session->save();

        return $session->getId();
    }

    /**
     * One request from a browser holding that session cookie.
     *
     * The guards and the in-memory session are dropped first, so nothing
     * carries over between requests the way it would inside a single process:
     * whatever the request knows, it read back out of the session driver.
     *
     * @return TestResponse<Response>
     */
    private function browse(string $sessionId, string $url): TestResponse
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->withCookie((string) config('session.cookie'), $sessionId)->get($url);
    }

    /**
     * Apply one change to an account, as an administrator.
     *
     * @param  array<string, mixed>  $change
     */
    private function change(User $account, array $change, User $administrator): void
    {
        $stored = $this->reloaded($account);

        $this->accounts->update($stored, UserAccountAttributes::fromFormData([
            'name' => $stored->name,
            'email' => $stored->email,
            'role' => $stored->role->value,
            'is_active' => (bool) $stored->is_active,
            // An empty box means "keep the current password".
            'password' => '',
            ...$change,
        ]), $administrator);
    }

    /**
     * The stored row, re-read. `fresh()` is nullable in general; here the row
     * was created by the test and its absence would be the bug.
     */
    private function reloaded(User $account): User
    {
        $stored = $account->fresh();

        $this->assertNotNull($stored);

        return $stored;
    }

    private function account(UserRole $role, string $email = 'person@example.tj'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => $role,
            'is_active' => true,
            'password' => self::PASSWORD,
        ]);
    }

    private function administrator(string $email = 'admin@example.tj'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
    }
}
