<?php

namespace Tests\Feature\Identity;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\UserAccountRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserAccountManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Account administration, at the boundary that actually decides.
 *
 * Filament hides what an operator may not do, but hiding a button is a
 * courtesy: every rule is asserted here, against the service, because that is
 * where a request which skipped the UI arrives.
 *
 * The role matrix under test is the provisional least-privilege one. Hydromet
 * has approved neither it nor the list of people who should hold each role.
 */
class UserAccountManagerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    private UserAccountManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(UserAccountManager::class);
    }

    // --- Authorization ----------------------------------------------------

    /**
     * @return array<string, array{UserRole, bool}>
     */
    public static function callersWhoMayNotManage(): array
    {
        return [
            'operator' => [UserRole::Operator, true],
            'editor' => [UserRole::Editor, true],
            'deactivated administrator' => [UserRole::Administrator, false],
        ];
    }

    #[Test]
    #[DataProvider('callersWhoMayNotManage')]
    public function only_an_active_administrator_may_create_an_account(UserRole $role, bool $isActive): void
    {
        $actor = User::factory()->create(['role' => $role, 'is_active' => $isActive]);

        $this->assertFalse($this->manager->allows($actor));
        $this->expectException(AuthorizationException::class);

        $this->manager->create($this->attributes(), $actor);
    }

    #[Test]
    #[DataProvider('callersWhoMayNotManage')]
    public function only_an_active_administrator_may_update_an_account(UserRole $role, bool $isActive): void
    {
        $actor = User::factory()->create(['role' => $role, 'is_active' => $isActive]);
        $account = User::factory()->create(['role' => UserRole::Editor]);

        $this->expectException(AuthorizationException::class);

        $this->manager->update($account, $this->attributes(['name' => 'Renamed']), $actor);
    }

    #[Test]
    public function an_unauthenticated_caller_may_not_manage_accounts(): void
    {
        $this->assertFalse($this->manager->allows(null));

        $this->expectException(AuthorizationException::class);

        $this->manager->create($this->attributes(), null);
    }

    // --- Creation ---------------------------------------------------------

    #[Test]
    public function creating_an_account_normalizes_it_and_hashes_the_password(): void
    {
        $administrator = $this->administrator();

        $account = $this->manager->create($this->attributes([
            'name' => '  Feruza Karimova  ',
            'email' => '  Feruza.Karimova@EXAMPLE.TJ ',
            'role' => UserRole::Operator->value,
            'is_active' => true,
        ]), $administrator);

        $this->assertSame('Feruza Karimova', $account->name);
        $this->assertSame('feruza.karimova@example.tj', $account->email);
        $this->assertSame(UserRole::Operator, $account->role);
        $this->assertTrue($account->is_active);

        // Stored hashed, and the plain value is not what is in the column.
        $this->assertNotSame(self::PASSWORD, $account->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password));
    }

    #[Test]
    public function creating_an_account_records_a_safe_audit_event(): void
    {
        $administrator = $this->administrator();

        $account = $this->manager->create($this->attributes([
            'email' => 'New.Person@example.tj',
            'role' => UserRole::Editor->value,
        ]), $administrator);

        $event = AuditEvent::query()->where('action', 'identity.user.created')->sole();

        $this->assertSame('user_account', $event->subject_type);
        $this->assertSame((string) $account->getKey(), $event->subject_id);
        $this->assertSame('new.person@example.tj', $event->subject_label);
        $this->assertSame($administrator->getKey(), $event->actor_id);

        $this->assertSame(['name', 'email', 'role', 'is_active'], $event->changes['fields']);
        $this->assertSame([], (array) $event->changes['before']);
        $this->assertSame([
            'name' => $account->name,
            'email' => 'new.person@example.tj',
            'role' => 'editor',
            'is_active' => true,
        ], (array) $event->changes['after']);
    }

    #[Test]
    public function an_email_already_in_use_is_refused(): void
    {
        $administrator = $this->administrator();
        User::factory()->create(['email' => 'taken@example.tj']);

        $this->expectException(UserAccountRuleViolation::class);

        $this->manager->create($this->attributes(['email' => 'TAKEN@example.tj']), $administrator);
    }

    // --- Update -----------------------------------------------------------

    #[Test]
    public function changing_business_fields_records_one_update_event(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.tj',
            'role' => UserRole::Editor,
            'is_active' => true,
        ]);

        $this->manager->update($account, $this->edit($account, [
            'name' => 'New Name',
            'email' => 'NEW@example.tj',
            'role' => UserRole::Operator->value,
            'is_active' => false,
        ]), $administrator);

        $event = AuditEvent::query()->where('action', 'identity.user.updated')->sole();

        $this->assertSame(['name', 'email', 'role', 'is_active'], $event->changes['fields']);
        $this->assertSame([
            'name' => 'Old Name',
            'email' => 'old@example.tj',
            'role' => 'editor',
            'is_active' => true,
        ], (array) $event->changes['before']);
        $this->assertSame([
            'name' => 'New Name',
            'email' => 'new@example.tj',
            'role' => 'operator',
            'is_active' => false,
        ], (array) $event->changes['after']);
    }

    #[Test]
    public function only_the_fields_that_actually_changed_are_recorded(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['name' => 'Same Name', 'role' => UserRole::Editor]);

        $this->manager->update($account, $this->edit($account, [
            'role' => UserRole::Operator->value,
        ]), $administrator);

        $event = AuditEvent::query()->where('action', 'identity.user.updated')->sole();

        $this->assertSame(['role'], $event->changes['fields']);
    }

    #[Test]
    public function a_save_that_changes_nothing_records_nothing(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['name' => 'Unchanged', 'role' => UserRole::Editor]);

        $this->manager->update($account, $this->edit($account), $administrator);

        $this->assertSame(0, AuditEvent::query()->where('subject_type', 'user_account')->count());
    }

    #[Test]
    public function a_password_change_records_only_a_credentials_event(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor]);
        $before = $account->password;

        $this->manager->update($account, $this->edit($account, [
            'password' => 'a-brand-new-secret-42',
        ]), $administrator);

        $events = AuditEvent::query()->where('subject_type', 'user_account')->get();

        $this->assertCount(1, $events);
        $this->assertSame('identity.user.credentials_changed', $events->first()?->action);

        $stored = $account->fresh();

        $this->assertNotNull($stored);
        $this->assertNotSame($before, $stored->password);
        $this->assertTrue(Hash::check('a-brand-new-secret-42', $stored->password));
    }

    #[Test]
    public function a_password_change_alongside_a_rename_records_both_events_separately(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['name' => 'Before', 'role' => UserRole::Editor]);

        $this->manager->update($account, $this->edit($account, [
            'name' => 'After',
            'password' => 'another-long-secret-77',
        ]), $administrator);

        $actions = AuditEvent::query()
            ->where('subject_type', 'user_account')
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame([
            'identity.user.updated',
            'identity.user.credentials_changed',
        ], $actions);

        $credentials = AuditEvent::query()
            ->where('action', 'identity.user.credentials_changed')
            ->sole();

        // That it changed is evidence; what it changed to is a credential.
        $this->assertSame(['password'], $credentials->changes['fields']);
        $this->assertSame([], (array) $credentials->changes['before']);
        $this->assertSame([], (array) $credentials->changes['after']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenAuditContent(): array
    {
        return [
            'the plain password' => [self::PASSWORD],
            'the new plain password' => ['a-brand-new-secret-42'],
            'a bcrypt hash prefix' => ['$2y$'],
            'the confirmation field' => ['password_confirmation'],
            'the remember token' => ['remember_token'],
            'a session payload' => ['payload'],
            'a reset token' => ['reset_token'],
            'the security stamp' => ['session_version'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenAuditContent')]
    public function no_credential_ever_reaches_the_audit_log(string $forbidden): void
    {
        $administrator = $this->administrator();

        $account = $this->manager->create($this->attributes(['email' => 'audited@example.tj']), $administrator);

        $this->manager->update($account, $this->edit($account, [
            'name' => 'Renamed',
            'role' => UserRole::Operator->value,
            'is_active' => false,
            'password' => 'a-brand-new-secret-42',
        ]), $administrator);

        $log = AuditEvent::query()->get()->toJson();

        $this->assertStringNotContainsString($forbidden, $log);
    }

    /**
     * The audit event and the change it describes are one write or neither.
     *
     * The failure is provoked at the database, not by swapping the recorder
     * out: what has to hold is that a real audit insert going wrong takes the
     * account change with it, and a stub that never reaches the database
     * would not prove that.
     */
    #[Test]
    public function a_failing_audit_write_rolls_the_account_change_back(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['name' => 'Original', 'role' => UserRole::Editor]);

        Event::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audit_events')) {
                throw new RuntimeException('The audit log is unavailable.');
            }
        });

        try {
            $this->manager->update(
                $account,
                $this->edit($account, ['name' => 'Renamed']),
                $administrator,
            );
            $this->fail('The update completed without its audit event.');
        } catch (RuntimeException) {
        }

        $this->assertSame('Original', $account->fresh()?->name);
        $this->assertSame(0, AuditEvent::query()->where('subject_type', 'user_account')->count());
    }

    // --- Self-management --------------------------------------------------

    /*
     * Two rules overlap here, and the order they are reported in matters.
     *
     * With a colleague still holding the role, refusing your own deactivation
     * is about you: go and ask them. When you are the only administrator left,
     * the same click is refused for a different reason — there would be no way
     * back in — and saying "ask another administrator" would send you looking
     * for someone who does not exist. So the last-administrator rule is
     * reported first, and each rule is asserted in the situation that is
     * genuinely its own.
     */

    #[Test]
    public function an_administrator_cannot_deactivate_themselves(): void
    {
        $administrator = $this->administrator();
        // A colleague, so the refusal below is the self rule and not the
        // last-administrator rule.
        $this->administrator('second@example.tj');

        $this->expectExceptionMessage(__('identity.errors.self_deactivation'));

        try {
            $this->manager->update(
                $administrator,
                $this->edit($administrator, ['is_active' => false]),
                $administrator,
            );
        } finally {
            $this->assertTrue($administrator->fresh()?->is_active);
        }
    }

    #[Test]
    public function an_administrator_cannot_change_their_own_role(): void
    {
        $administrator = $this->administrator();
        $this->administrator('second@example.tj');

        $this->expectExceptionMessage(__('identity.errors.self_role_change'));

        try {
            $this->manager->update(
                $administrator,
                $this->edit($administrator, ['role' => UserRole::Operator->value]),
                $administrator,
            );
        } finally {
            $this->assertSame(UserRole::Administrator, $administrator->fresh()?->role);
        }
    }

    #[Test]
    public function an_administrator_may_still_rename_themselves(): void
    {
        $administrator = $this->administrator();

        $this->manager->update(
            $administrator,
            $this->edit($administrator, ['name' => 'Renamed Administrator']),
            $administrator,
        );

        $this->assertSame('Renamed Administrator', $administrator->fresh()?->name);
    }

    #[Test]
    public function an_administrator_may_change_their_own_password(): void
    {
        $administrator = $this->administrator();

        $this->manager->update(
            $administrator,
            $this->edit($administrator, ['password' => 'a-brand-new-secret-42']),
            $administrator,
        );

        $this->assertTrue(Hash::check('a-brand-new-secret-42', $this->reloaded($administrator)->password));
    }

    // --- The last administrator -------------------------------------------

    #[Test]
    public function the_last_active_administrator_cannot_be_deactivated(): void
    {
        $administrator = $this->administrator();

        $this->assertSame(1, $this->activeAdministratorCount());
        $this->expectExceptionMessage(__('identity.errors.last_active_administrator'));

        try {
            $this->manager->update(
                $administrator,
                $this->edit($administrator, ['is_active' => false]),
                $administrator,
            );
        } finally {
            $this->assertTrue($administrator->fresh()?->is_active);
            $this->assertSame(1, $this->activeAdministratorCount());
        }
    }

    #[Test]
    public function the_last_active_administrator_cannot_be_demoted(): void
    {
        $administrator = $this->administrator();

        $this->assertSame(1, $this->activeAdministratorCount());
        $this->expectExceptionMessage(__('identity.errors.last_active_administrator'));

        try {
            $this->manager->update(
                $administrator,
                $this->edit($administrator, ['role' => UserRole::Editor->value]),
                $administrator,
            );
        } finally {
            $this->assertSame(UserRole::Administrator, $administrator->fresh()?->role);
        }
    }

    #[Test]
    public function one_of_two_administrators_may_be_deactivated_by_the_other(): void
    {
        $administrator = $this->administrator();
        $other = $this->administrator('other@example.tj');

        $this->manager->update($other, $this->edit($other, ['is_active' => false]), $administrator);

        $this->assertFalse($other->fresh()?->is_active);
        $this->assertTrue($administrator->fresh()?->is_active);
        $this->assertSame(1, $this->activeAdministratorCount());
    }

    #[Test]
    public function one_of_two_administrators_may_be_demoted_by_the_other(): void
    {
        $administrator = $this->administrator();
        $other = $this->administrator('other@example.tj');

        $this->manager->update(
            $other,
            $this->edit($other, ['role' => UserRole::Operator->value]),
            $administrator,
        );

        $this->assertSame(UserRole::Operator, $other->fresh()?->role);
        $this->assertSame(1, $this->activeAdministratorCount());
    }

    /**
     * A deactivated administrator and a non-administrator are not ways in, so
     * neither counts towards the one that must remain.
     */
    #[Test]
    public function inactive_and_non_administrator_accounts_do_not_count_as_a_way_in(): void
    {
        $administrator = $this->administrator();
        User::factory()->create(['role' => UserRole::Administrator, 'is_active' => false]);
        User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        // Four accounts exist, but only one of them can reach the panel.
        $this->assertSame(4, User::query()->count());
        $this->assertSame(1, $this->activeAdministratorCount());

        $this->expectExceptionMessage(__('identity.errors.last_active_administrator'));

        $this->manager->update(
            $administrator,
            $this->edit($administrator, ['is_active' => false]),
            $administrator,
        );
    }

    // --- Session revocation -----------------------------------------------

    /*
     * What ends a session is the account's security stamp, not a row in the
     * `sessions` table: `.env.example` selects the Redis session driver, where
     * there is no such row to delete and no key that may be searched for. So
     * these tests assert the stamp, and
     * `tests/Feature/Identity/SessionRevocationTest.php` asserts what a person
     * with a session actually experiences when it moves.
     */

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function changesThatEndSessions(): array
    {
        return [
            'deactivation' => [['is_active' => false, 'password' => '']],
            'role change' => [['role' => 'operator', 'password' => '']],
            'password change' => [['password' => 'a-brand-new-secret-42']],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[Test]
    #[DataProvider('changesThatEndSessions')]
    public function a_change_of_access_moves_the_security_stamp(array $change): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor]);
        $bystander = User::factory()->create(['role' => UserRole::Editor]);

        $before = $account->session_version;

        $this->manager->update($account, $this->edit($account, $change), $administrator);

        $this->assertSame($before + 1, $this->reloaded($account)->session_version);
        // Only this account's sessions end.
        $this->assertSame(
            $bystander->session_version,
            $this->reloaded($bystander)->session_version,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function changesThatKeepSessions(): array
    {
        return [
            'rename' => [['name' => 'Renamed']],
            'a corrected e-mail address' => [['email' => 'corrected@example.tj']],
            'nothing at all' => [[]],
        ];
    }

    /**
     * None of these changes what the person may do, and signing everyone out
     * over a corrected spelling is a cost with no benefit.
     *
     * @param  array<string, mixed>  $change
     */
    #[Test]
    #[DataProvider('changesThatKeepSessions')]
    public function an_edit_that_changes_no_access_leaves_the_stamp_alone(array $change): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor]);

        $before = $account->session_version;

        $this->manager->update($account, $this->edit($account, $change), $administrator);

        $this->assertSame($before, $this->reloaded($account)->session_version);
    }

    /**
     * Letting someone back in does not restore the sessions they had before it:
     * the stamp only ever moves forward.
     */
    #[Test]
    public function reactivation_does_not_bring_an_old_session_back(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        $this->manager->update($account, $this->edit($account, ['is_active' => false]), $administrator);
        $afterDeactivation = $this->reloaded($account)->session_version;

        $this->manager->update($account, $this->edit($account, ['is_active' => true]), $administrator);

        $this->assertSame($afterDeactivation, $this->reloaded($account)->session_version);
    }

    /**
     * The stamp and the change that required it are one write or neither. A
     * session must not be ended by an update that then rolled back.
     */
    #[Test]
    public function a_failing_audit_write_rolls_the_security_stamp_back(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        $before = $account->session_version;

        Event::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audit_events')) {
                throw new RuntimeException('The audit log is unavailable.');
            }
        });

        try {
            $this->manager->update(
                $account,
                $this->edit($account, ['is_active' => false]),
                $administrator,
            );
            $this->fail('The update completed without its audit event.');
        } catch (RuntimeException) {
        }

        $stored = $this->reloaded($account);

        $this->assertSame($before, $stored->session_version);
        $this->assertTrue($stored->is_active);
    }

    /**
     * The stamp is internal bookkeeping. It is not a field an administrator
     * set, so it has no place in the record of what they changed.
     */
    #[Test]
    public function the_security_stamp_never_reaches_the_audit_log(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor]);

        $this->manager->update($account, $this->edit($account, [
            'is_active' => false,
            'password' => 'a-brand-new-secret-42',
        ]), $administrator);

        $updated = AuditEvent::query()->where('action', 'identity.user.updated')->sole();

        $this->assertSame(['is_active'], $updated->changes['fields']);
        $this->assertArrayNotHasKey('session_version', (array) $updated->changes['after']);
    }

    // --- Helpers ----------------------------------------------------------

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

    private function activeAdministratorCount(): int
    {
        return User::query()
            ->where('role', UserRole::Administrator)
            ->where('is_active', true)
            ->count();
    }

    private function administrator(string $email = 'admin@example.tj'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
    }

    /**
     * Attributes for a new account.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function attributes(array $overrides = []): UserAccountAttributes
    {
        return UserAccountAttributes::fromFormData([
            'name' => 'Test Person',
            'email' => 'test.person@example.tj',
            'role' => UserRole::Editor->value,
            'is_active' => true,
            'password' => self::PASSWORD,
            ...$overrides,
        ]);
    }

    /**
     * Attributes for an edit, starting from what the account already holds.
     *
     * A test then states only its delta, which is also what the form submits:
     * every field is present, and only the ones that differ are changes.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function edit(User $account, array $overrides = []): UserAccountAttributes
    {
        return UserAccountAttributes::fromFormData([
            'name' => $account->name,
            'email' => $account->email,
            'role' => $account->role->value,
            'is_active' => (bool) $account->is_active,
            // An empty box means "keep the current password".
            'password' => '',
            ...$overrides,
        ]);
    }
}
