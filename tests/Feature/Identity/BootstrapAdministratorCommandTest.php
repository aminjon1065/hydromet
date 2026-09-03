<?php

namespace Tests\Feature\Identity;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The one-time bootstrap of the first administrator.
 *
 * Every other account is created by an administrator. The first one cannot be,
 * and the alternatives are worse: a seeded account puts a shared password in
 * the repository, and `make:filament-user` — which the documentation used to
 * point at — creates an editor, every time you run it, with no audit entry and
 * no rule about when it may be used.
 *
 * So the safety here is the narrowness, and that is what these tests assert:
 * one administrator, only on an empty installation, recorded, and refused
 * afterwards.
 */
class BootstrapAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    #[Test]
    public function it_creates_exactly_one_active_administrator(): void
    {
        $this->bootstrap()->assertSuccessful();

        $account = User::query()->sole();

        $this->assertSame('Feruza Karimova', $account->name);
        $this->assertSame(UserRole::Administrator, $account->role);
        $this->assertTrue($account->is_active);
        $this->assertSame(1, User::query()->count());
    }

    /**
     * Trimmed and lower-cased exactly as the panel form normalizes it, so the
     * address that was typed and the address that signs in are the same one.
     */
    #[Test]
    public function it_normalizes_the_name_and_the_address(): void
    {
        $this->bootstrap(name: '  Feruza Karimova  ', email: '  Feruza.Karimova@EXAMPLE.TJ ')
            ->assertSuccessful();

        $account = User::query()->sole();

        $this->assertSame('Feruza Karimova', $account->name);
        $this->assertSame('feruza.karimova@example.tj', $account->email);
    }

    #[Test]
    public function the_password_is_stored_hashed_and_never_echoed(): void
    {
        $this->bootstrap()->assertSuccessful();

        $account = User::query()->sole();

        $this->assertNotSame(self::PASSWORD, $account->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function passwordsThePolicyRefuses(): array
    {
        return [
            'too short' => ['short1', 'short1'],
            'letters only' => ['passwordwithoutdigits', 'passwordwithoutdigits'],
            'digits only' => ['9182736455647382', '9182736455647382'],
            'confirmation does not match' => [self::PASSWORD, 'something-else-entirely'],
        ];
    }

    /**
     * The same policy the Filament form applies, from the same default. A
     * refusal creates nothing at all — not a weak account, not a half-made one.
     */
    #[Test]
    #[DataProvider('passwordsThePolicyRefuses')]
    public function it_refuses_a_password_the_policy_refuses(string $password, string $confirmation): void
    {
        $this->bootstrap(password: $password, confirmation: $confirmation)->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    // --- Evidence ----------------------------------------------------------

    #[Test]
    public function it_records_a_safe_creation_event(): void
    {
        $this->bootstrap()->assertSuccessful();

        $account = User::query()->sole();
        $event = AuditEvent::query()->sole();

        $this->assertSame('identity.user.created', $event->action);
        $this->assertSame('user_account', $event->subject_type);
        $this->assertSame((string) $account->getKey(), $event->subject_id);
        $this->assertSame('feruza.karimova@example.tj', $event->subject_label);

        // No actor, and deliberately so: the account being created is the first
        // one on the installation, so there is nobody to name.
        $this->assertNull($event->actor_id);

        $this->assertSame(['name', 'email', 'role', 'is_active'], $event->changes['fields']);
        $this->assertSame([], (array) $event->changes['before']);
        $this->assertSame([
            'name' => 'Feruza Karimova',
            'email' => 'feruza.karimova@example.tj',
            'role' => 'administrator',
            'is_active' => true,
        ], (array) $event->changes['after']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenAuditContent(): array
    {
        return [
            'the plain password' => [self::PASSWORD],
            'a bcrypt hash prefix' => ['$2y$'],
            'the confirmation field' => ['password_confirmation'],
            'the remember token' => ['remember_token'],
            'a reset token' => ['reset_token'],
            'a session payload' => ['payload'],
            'the security stamp' => ['session_version'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenAuditContent')]
    public function no_credential_reaches_the_audit_log(string $forbidden): void
    {
        $this->bootstrap()->assertSuccessful();

        $this->assertStringNotContainsString($forbidden, AuditEvent::query()->get()->toJson());
    }

    /**
     * The account and its audit entry are one write or neither: an
     * administrator nobody can account for is worse than no administrator.
     *
     * The failure is provoked at the database rather than by swapping the
     * recorder out, so what is exercised is a real audit insert going wrong.
     */
    #[Test]
    public function a_failing_audit_write_rolls_the_account_back(): void
    {
        Event::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audit_events')) {
                throw new RuntimeException('The audit log is unavailable.');
            }
        });

        try {
            $this->bootstrap()->run();
            $this->fail('The administrator was created without its audit event.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, User::query()->count());
    }

    // --- It runs once ------------------------------------------------------

    #[Test]
    public function a_second_run_is_refused_and_changes_nothing(): void
    {
        $this->bootstrap()->assertSuccessful();

        $first = User::query()->sole();

        $this->command()
            ->expectsOutputToContain((string) __('identity.bootstrap.not_empty'))
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, AuditEvent::query()->count());
        $this->assertSame($first->email, User::query()->sole()->email);
    }

    /**
     * Not only after its own run: any existing account closes it, including one
     * that could never sign in.
     */
    #[Test]
    public function it_is_refused_whenever_any_account_exists(): void
    {
        User::factory()->create(['role' => UserRole::Editor, 'is_active' => false]);

        $this->command()->assertFailed();

        $this->assertSame(0, User::query()->where('role', UserRole::Administrator)->count());
        $this->assertSame(1, User::query()->count());
    }

    /**
     * It asks nothing before refusing, so a password is never typed into a run
     * that was going to be rejected anyway.
     */
    #[Test]
    public function it_refuses_before_asking_for_anything(): void
    {
        User::factory()->create(['role' => UserRole::Editor]);

        // `expectsQuestion` is deliberately absent: an unexpected question would
        // fail the test rather than silently receive an empty answer.
        $this->command()->assertFailed();
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * The command with every answer supplied, addressed by the same translated
     * prompts it asks with, so the test does not depend on the locale.
     */
    private function bootstrap(
        string $name = 'Feruza Karimova',
        string $email = 'feruza.karimova@example.tj',
        string $password = self::PASSWORD,
        ?string $confirmation = null,
    ): PendingCommand {
        return $this->command()
            ->expectsQuestion((string) __('identity.fields.name'), $name)
            ->expectsQuestion((string) __('identity.fields.email'), $email)
            ->expectsQuestion((string) __('identity.fields.password'), $password)
            ->expectsQuestion(
                (string) __('identity.fields.password_confirmation'),
                $confirmation ?? $password,
            );
    }

    /**
     * The command, not yet run.
     *
     * `artisan()` returns an exit code instead of a pending command when it has
     * already executed; narrowing here keeps every caller's signature honest.
     */
    private function command(): PendingCommand
    {
        $pending = $this->artisan('users:bootstrap-administrator');

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
