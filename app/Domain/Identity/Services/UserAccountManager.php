<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\UserAccountRuleViolation;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place a user account is created or changed.
 *
 * Filament calls into this rather than spreading the rules across resource and
 * page classes, because the rules are not presentation: hiding a button is a
 * courtesy to the person clicking, not a control. Every rule below is therefore
 * re-checked here, where a request that skipped the UI still has to pass.
 *
 * The role matrix these rules enforce is the provisional least-privilege one in
 * `docs/01-product-scope.md`. Hydromet has not approved the final matrix or the
 * list of people who should hold each role
 * (`docs/08-hydromet-input-checklist.md`, section 6), so nothing here should be
 * read as an approved authorization model.
 *
 * Accounts are never deleted. An account carries audit history, and history
 * without an actor is worth less than history with a deactivated one, so
 * "remove someone" means `is_active = false`.
 */
final class UserAccountManager
{
    /**
     * Fields an administrator may change and the audit log records. `password`
     * is absent on purpose: it has its own event and its value is never stored
     * in an audit payload.
     *
     * @var array<int, string>
     */
    private const AUDITED_FIELDS = ['name', 'email', 'role', 'is_active'];

    /**
     * The PostgreSQL advisory lock the first-administrator bootstrap
     * serializes on.
     *
     * A fixed number, written out here and never derived. An advisory lock is
     * only a lock if every process asks for the same one, and a value computed
     * at runtime — from a hash of a table name, a class name, an application
     * key — can change without anyone meaning it to: a different PHP build, a
     * reworded string, a renamed class, and two processes are politely locking
     * two different things. The digits carry no meaning beyond being ours; they
     * are shaped after this feature's migration date so a collision with
     * another application on a shared cluster is unlikely.
     */
    public const BOOTSTRAP_LOCK = 20_260_902_120_013;

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @throws AuthorizationException
     * @throws UserAccountRuleViolation
     */
    public function create(UserAccountAttributes $attributes, ?Authenticatable $actor = null): User
    {
        $administrator = $this->administrator($actor);

        return DB::transaction(fn (): User => $this->writeNewAccount(
            $attributes,
            $attributes->role ?? UserRole::Editor,
            $attributes->isActive ?? true,
            $administrator->getKey(),
        ));
    }

    /**
     * Create the very first administrator, on an installation that has none.
     *
     * Every other account is created by an administrator. The first one cannot
     * be: on an empty installation there is nobody to ask, and the alternatives
     * — a seeded account with a password in the repository, or a default that
     * every deployment shares — are worse than a one-time command.
     *
     * The narrowness is the safety. It refuses the moment a single account
     * exists, including the one it just created, so it is a bootstrap and never
     * a second way to add people; nothing routes to it, so it is unreachable
     * over HTTP and from Filament; and `create()` above is untouched, so
     * ordinary account creation still requires an active administrator.
     *
     * "No account exists" is the one condition that cannot be held by locking
     * rows, because there are none to lock. Two processes started together
     * would both read an empty table and both create an administrator. So the
     * transaction takes a lock that does not depend on rows first, and only
     * then asks the question — the second process asks it after the first has
     * committed, and is refused.
     *
     * @throws UserAccountRuleViolation
     * @throws RuntimeException on a database the bootstrap cannot serialize
     */
    public function bootstrapFirstAdministrator(UserAccountAttributes $attributes): User
    {
        // Before the transaction, so an unsupported database is a refusal
        // rather than a connection that opens and then finds itself unable to
        // do the one thing this method needs.
        $driver = $this->bootstrapDriver();

        return DB::transaction(function () use ($attributes, $driver): User {
            $this->lockBootstrap($driver);

            // Asked after the lock, never before: this is the read the lock
            // exists to make trustworthy.
            if (User::query()->exists()) {
                throw UserAccountRuleViolation::installationAlreadyHasAccounts();
            }

            // Role and activation are fixed here rather than taken from the
            // caller: the only account this may create is an administrator who
            // can sign in. The actor is null because the person running the
            // command has no account yet — that is the situation being fixed.
            return $this->writeNewAccount($attributes, UserRole::Administrator, true, null);
        });
    }

    /**
     * The driver the bootstrap will run on, refused unless it can be
     * serialized.
     *
     * Continuing on an unrecognised driver would mean running the one operation
     * whose whole safety is the lock without the lock — silently, and only
     * visibly wrong on the day two people run the command at once.
     *
     * @throws RuntimeException
     */
    private function bootstrapDriver(): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(sprintf(
                'The first administrator cannot be created on a [%s] connection: '
                .'this command has no way to serialize itself there, and creating '
                .'the account without one risks two administrators from two '
                .'simultaneous runs. Supported: pgsql, sqlite.',
                $driver,
            ));
        }

        return $driver;
    }

    /**
     * Hold the bootstrap against every other process, for the rest of the
     * transaction.
     *
     * PostgreSQL takes a transaction-scoped advisory lock. It is not attached
     * to a row, which is the point — the condition being protected is that
     * there are no rows — and the server releases it on commit or rollback, so
     * a crashed run cannot leave the command permanently blocked.
     *
     * SQLite has no advisory locks, and a transaction it opened deferred holds
     * nothing until it first writes: two connections would both read the empty
     * table happily. Writing `user_version` back as itself upgrades the
     * transaction to a write lock, which SQLite grants to one connection at a
     * time. It is a genuine write, so the lock is real, and a genuine no-op, so
     * nothing in the database changes.
     */
    private function lockBootstrap(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::statement('select pg_advisory_xact_lock(cast(? as bigint))', [self::BOOTSTRAP_LOCK]);

            return;
        }

        // Read and written back unchanged. Interpolated because SQLite does not
        // accept a binding in a PRAGMA, and safe to interpolate because the
        // value has been through an int cast.
        $stored = (array) DB::selectOne('pragma user_version');
        $current = (int) ($stored['user_version'] ?? 0);

        DB::statement('pragma user_version = '.$current);
    }

    /**
     * The insert both creation paths share. Call inside a transaction.
     *
     * @throws UserAccountRuleViolation
     */
    private function writeNewAccount(
        UserAccountAttributes $attributes,
        UserRole $role,
        bool $isActive,
        ?int $actorId,
    ): User {
        $email = $attributes->email;

        if ($email === null || $attributes->name === null || $attributes->password === null) {
            throw UserAccountRuleViolation::withMessages([
                'email' => __('identity.errors.incomplete_account'),
            ]);
        }

        $this->guardEmailIsFree($email, null);

        $account = new User;
        $account->name = $attributes->name;
        $account->email = $email;
        $account->role = $role;
        $account->is_active = $isActive;
        // Assigned in plain and hashed by the model's `hashed` cast, so the
        // plain value never survives past this statement.
        $account->password = $attributes->password;
        $account->save();

        $this->auditRecorder->record(
            action: 'identity.user.created',
            subjectType: 'user_account',
            subjectId: $account->getKey(),
            changes: [
                'fields' => self::AUDITED_FIELDS,
                'before' => (object) [],
                'after' => $this->auditableState($account),
            ],
            actorId: $actorId,
            subjectLabel: $account->email,
        );

        return $account;
    }

    /**
     * @throws AuthorizationException
     * @throws UserAccountRuleViolation
     */
    public function update(User $account, UserAccountAttributes $attributes, ?Authenticatable $actor = null): User
    {
        $administrator = $this->administrator($actor);

        return DB::transaction(function () use ($account, $attributes, $administrator): User {
            // Lock order, and it matters: the administrator set first, then the
            // account being edited. Every writer takes the same two locks in
            // the same order — and the set itself is ordered by id — so two
            // concurrent edits queue behind each other instead of each holding
            // what the other is waiting for.
            //
            // Locking the administrators first also makes the count that
            // follows a decision rather than a guess: two administrators cannot
            // each be told "there is another one" while both are being demoted.
            $activeAdministrators = $this->lockActiveAdministrators();
            $this->refreshUnderLock($account);

            $before = $this->auditableState($account);

            $this->applyIdentity($account, $attributes);
            $this->applyRole($account, $attributes, $administrator, $activeAdministrators);
            $this->applyActivation($account, $attributes, $administrator, $activeAdministrators);

            $roleChanged = $account->isDirty('role');
            $deactivated = $account->isDirty('is_active') && $account->is_active === false;
            $passwordChanged = $attributes->password !== null;

            if ($passwordChanged) {
                $account->password = $attributes->password;
            }

            $changedFields = array_values(array_intersect(
                self::AUDITED_FIELDS,
                array_keys($account->getDirty()),
            ));

            if ($changedFields === [] && ! $passwordChanged) {
                // Nothing moved. Writing an audit event here would fill the log
                // with entries that record no decision.
                return $account;
            }

            if ($deactivated || $roleChanged || $passwordChanged) {
                // Moved in the same statement as the change that requires it,
                // so the account change and the revocation commit together or
                // not at all. A rename or a new e-mail address leaves it alone:
                // neither changes what the person may do, and signing everyone
                // out over a corrected spelling is a cost with no benefit.
                $this->stampNewSessionVersion($account);
            }

            $account->save();

            if ($changedFields !== []) {
                $this->auditRecorder->record(
                    action: 'identity.user.updated',
                    subjectType: 'user_account',
                    subjectId: $account->getKey(),
                    changes: [
                        'fields' => $changedFields,
                        'before' => $this->only($before, $changedFields),
                        'after' => $this->only($this->auditableState($account), $changedFields),
                    ],
                    actorId: $administrator->getKey(),
                    subjectLabel: $account->email,
                );
            }

            if ($passwordChanged) {
                $this->auditRecorder->record(
                    action: 'identity.user.credentials_changed',
                    subjectType: 'user_account',
                    subjectId: $account->getKey(),
                    // Deliberately valueless. That a password changed is
                    // administrative evidence; what it changed to, or from, is
                    // a credential and has no place in a readable log.
                    changes: [
                        'fields' => ['password'],
                        'before' => (object) [],
                        'after' => (object) [],
                    ],
                    actorId: $administrator->getKey(),
                    subjectLabel: $account->email,
                );
            }

            return $account;
        });
    }

    /**
     * Only an authenticated, active administrator manages accounts.
     *
     * @throws AuthorizationException
     */
    public function administrator(?Authenticatable $actor = null): User
    {
        $actor ??= Auth::user();

        if (! $actor instanceof User || ! $actor->is_active || $actor->role !== UserRole::Administrator) {
            throw new AuthorizationException(__('identity.errors.not_permitted'));
        }

        return $actor;
    }

    /**
     * Whether the caller may manage accounts at all, for the panel to decide
     * what to show. The service still re-checks on every write.
     */
    public function allows(?Authenticatable $actor = null): bool
    {
        try {
            $this->administrator($actor);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    private function applyIdentity(User $account, UserAccountAttributes $attributes): void
    {
        if ($attributes->name !== null) {
            $account->name = $attributes->name;
        }

        if ($attributes->email !== null && $attributes->email !== $account->email) {
            $this->guardEmailIsFree($attributes->email, $account);
            $account->email = $attributes->email;
        }
    }

    /**
     * @param  Collection<int, User>  $activeAdministrators
     */
    private function applyRole(
        User $account,
        UserAccountAttributes $attributes,
        User $administrator,
        Collection $activeAdministrators,
    ): void {
        if ($attributes->role === null || $attributes->role === $account->role) {
            return;
        }

        // The last-administrator rule is checked first on purpose. When you
        // are the only administrator left, "this is the last one" is the
        // reason that matters; "you cannot change your own role" would send
        // you looking for a colleague who does not exist.
        if ($attributes->role !== UserRole::Administrator) {
            $this->guardAnotherAdministratorRemains($account, $activeAdministrators, 'role');
        }

        if ($account->is($administrator)) {
            throw UserAccountRuleViolation::selfRoleChange();
        }

        $account->role = $attributes->role;
    }

    /**
     * @param  Collection<int, User>  $activeAdministrators
     */
    private function applyActivation(
        User $account,
        UserAccountAttributes $attributes,
        User $administrator,
        Collection $activeAdministrators,
    ): void {
        if ($attributes->isActive === null || $attributes->isActive === $account->is_active) {
            return;
        }

        if ($attributes->isActive === false) {
            // Same order as above: being the last way in is the more useful
            // thing to be told.
            $this->guardAnotherAdministratorRemains($account, $activeAdministrators, 'is_active');

            if ($account->is($administrator)) {
                throw UserAccountRuleViolation::selfDeactivation();
            }
        }

        $account->is_active = $attributes->isActive;
    }

    /**
     * The portal must keep at least one way in.
     *
     * Counted from the locked set rather than a fresh query, so the answer
     * cannot change between the check and the write.
     *
     * @param  Collection<int, User>  $activeAdministrators
     */
    private function guardAnotherAdministratorRemains(
        User $account,
        Collection $activeAdministrators,
        string $field,
    ): void {
        $isLastWayIn = $activeAdministrators
            ->reject(static fn (User $administrator): bool => $administrator->is($account))
            ->isEmpty();

        if ($account->role === UserRole::Administrator && $account->is_active && $isLastWayIn) {
            throw UserAccountRuleViolation::lastActiveAdministrator($field);
        }
    }

    /**
     * Every active administrator, locked for the rest of the transaction.
     *
     * The rows are selected rather than counted because `SELECT count(*) ...
     * FOR UPDATE` is not valid SQL on PostgreSQL. SQLite ignores the lock and
     * relies on its single writer, so the rule holds on both.
     *
     * @return Collection<int, User>
     */
    private function lockActiveAdministrators(): Collection
    {
        return User::query()
            ->where('role', UserRole::Administrator)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function guardEmailIsFree(string $email, ?User $except): void
    {
        $taken = User::query()
            ->where('email', $email)
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->exists();

        if ($taken) {
            throw UserAccountRuleViolation::emailAlreadyTaken();
        }
    }

    /**
     * The account's row, re-read under a lock held for the rest of the
     * transaction.
     *
     * The caller's instance is updated in place rather than replaced, so it
     * keeps the object identity Filament and the caller already hold, but every
     * value from here on is the stored row — not whatever the form was rendered
     * from, which may be minutes old.
     *
     * @throws UserAccountRuleViolation
     */
    private function refreshUnderLock(User $account): void
    {
        $locked = User::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            // Accounts are never deleted, so this is unreachable through any
            // supported path; refusing beats writing to a row that is gone.
            throw UserAccountRuleViolation::withMessages([
                'email' => __('identity.errors.account_missing'),
            ]);
        }

        $account->setRawAttributes($locked->getAttributes(), sync: true);
    }

    /**
     * Move the account's security stamp, which is what actually ends its
     * sessions.
     *
     * Every authenticated panel request compares the version stored in the
     * session against this column
     * (`App\Http\Middleware\EnforceAccountSessionVersion`), so a session opened
     * before the change is refused on the very next request — on Redis, the
     * database, files or the array driver alike, because none of them is asked
     * to find anything. The alternative, searching a session store for one
     * account's sessions, is only possible on one driver and would mean
     * scanning every key on another.
     *
     * This is the whole of the revocation: nothing is deleted from any session
     * store, on any driver. The stored session is left to expire on the
     * driver's own lifetime and garbage collection, because it can no longer
     * be used to reach anything. Deleting it would add a second write, on a
     * store this transaction does not own, that could only fail after the
     * change had already been committed — an error shown to the administrator
     * for work that was in fact done.
     *
     * Read under the same lock as the rest of the update and written in the
     * same statement, so two concurrent changes cannot both stamp the same
     * version.
     */
    private function stampNewSessionVersion(User $account): void
    {
        $account->session_version = $account->session_version + 1;
    }

    /**
     * The safe projection of an account: what an administrator set, and nothing
     * that could authenticate as them.
     *
     * @return array<string, mixed>
     */
    private function auditableState(User $account): array
    {
        return [
            'name' => $account->name,
            'email' => $account->email,
            'role' => $account->role->value,
            'is_active' => $account->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function only(array $state, array $fields): array
    {
        $selected = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $state)) {
                $selected[$field] = $state[$field];
            }
        }

        return $selected;
    }
}
