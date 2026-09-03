<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Data\UserAccountAttributes;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserAccountManager;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Two people running the bootstrap at the same moment must produce one
 * administrator, not two.
 *
 * "No account exists" is the one condition in this application that cannot be
 * held by locking rows, because the whole point is that there are none. Row
 * locks protect rows; an empty table has nothing to protect. Both processes
 * would read an empty table, and both would be right at the moment they read
 * it.
 *
 * So the bootstrap takes a lock that does not depend on rows before it asks the
 * question. These tests are about that lock: that it is really held, that it is
 * really waited for, that it goes away when the transaction does, and that a
 * database where it cannot be taken is refused rather than quietly run without
 * it.
 */
class BootstrapSerializationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    private UserAccountManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(UserAccountManager::class);
    }

    // --- The lock identifier -----------------------------------------------

    /**
     * An advisory lock is only a lock if every process asks for the same one.
     *
     * The value is asserted literally, and that is the point of the test: a
     * number derived at runtime — from a hash of a class name, a table name, an
     * application key — can change with a PHP build, a rename or a reworded
     * string, and two processes then lock two different things and neither
     * waits for the other. This assertion fails if anyone replaces the constant
     * with something computed.
     */
    #[Test]
    public function the_lock_identifier_is_a_fixed_number(): void
    {
        $this->assertSame(20_260_902_120_013, UserAccountManager::BOOTSTRAP_LOCK);
    }

    // --- PostgreSQL --------------------------------------------------------

    /**
     * The lock is held for the whole bootstrap transaction, so anybody else is
     * still waiting when the account is written.
     *
     * `RefreshDatabase` never commits, which is convenient here: the
     * transaction the bootstrap ran in is still open, so a second connection
     * asking for the same lock is asking exactly what a second process would
     * ask mid-run.
     */
    #[Test]
    public function postgresql_holds_the_lock_for_the_whole_bootstrap(): void
    {
        $this->skipUnlessPostgres();

        $this->manager->bootstrapFirstAdministrator($this->attributes());

        $probe = $this->probeConnection();

        $this->assertFalse(
            $this->tryLock($probe, UserAccountManager::BOOTSTRAP_LOCK),
            'A second connection was able to take the bootstrap lock while a bootstrap held it.',
        );

        // The control: the probe can take a different lock, so the refusal
        // above is this lock being held and not the probe being broken.
        $this->assertTrue(
            $this->tryLock($probe, UserAccountManager::BOOTSTRAP_LOCK + 1),
            'The probe could not take an unrelated lock, so it proves nothing.',
        );
    }

    /**
     * Transaction-scoped, not session-scoped.
     *
     * It matters because a session-scoped lock survives a crashed or abandoned
     * run and would block the command until somebody noticed and released it by
     * hand. `pg_advisory_unlock` releases only session locks and answers false
     * when there is none — which is the answer expected here, from the very
     * connection that holds the transaction lock.
     */
    #[Test]
    public function postgresql_takes_a_lock_the_transaction_releases_by_itself(): void
    {
        $this->skipUnlessPostgres();

        $this->manager->bootstrapFirstAdministrator($this->attributes());

        $released = DB::selectOne(
            'select pg_advisory_unlock(cast(? as bigint)) as released',
            [UserAccountManager::BOOTSTRAP_LOCK],
        );

        $this->assertFalse(
            (bool) ((array) $released)['released'],
            'The bootstrap left a session-level lock behind, which nothing would release.',
        );
    }

    /**
     * The second process waits, and then finds the account.
     *
     * Someone else holds the lock; the bootstrap does not read past it and
     * declare the table empty, it blocks — proved here by giving it a lock
     * timeout and watching it spend the timeout waiting. Once the lock is free
     * the same call goes through, so the wait is a wait and not a failure mode.
     */
    #[Test]
    public function postgresql_makes_a_second_bootstrap_wait_for_the_first(): void
    {
        $this->skipUnlessPostgres();

        $competitor = $this->probeConnection();
        $competitor->beginTransaction();
        $competitor->statement(
            'select pg_advisory_xact_lock(cast(? as bigint))',
            [UserAccountManager::BOOTSTRAP_LOCK],
        );

        // Without this the test would block until somebody killed it, which is
        // itself the evidence: the bootstrap waits rather than proceeding.
        DB::statement('set local lock_timeout = 750');

        try {
            $this->manager->bootstrapFirstAdministrator($this->attributes());
            $this->fail('The bootstrap read the empty table while another process held the lock.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('lock timeout', mb_strtolower($exception->getMessage()));
        }

        $this->assertSame(0, User::query()->count());

        // The competitor finishes; the lock goes with its transaction.
        $competitor->rollBack();

        $account = $this->manager->bootstrapFirstAdministrator($this->attributes());

        $this->assertSame(UserRole::Administrator, $account->role);
        $this->assertSame(1, User::query()->count());
    }

    // --- SQLite ------------------------------------------------------------

    /**
     * SQLite has no advisory locks, and a deferred transaction holds nothing
     * until it first writes: two connections would both read the empty table
     * and both be satisfied. The bootstrap therefore writes `user_version` back
     * as itself, which is a real write — SQLite grants the write lock to one
     * connection at a time — and a real no-op.
     *
     * Asserted at the driver, on two connections to a scratch file, because
     * that is the claim: this exact statement takes the lock. It runs on every
     * driver the suite is pointed at, since it is about SQLite itself rather
     * than about the connection the application happens to be using.
     */
    #[Test]
    public function the_sqlite_lock_statement_takes_sqlites_write_lock(): void
    {
        $path = $this->scratchDatabase();

        $holder = new PDO('sqlite:'.$path);
        $rival = new PDO('sqlite:'.$path);
        $holder->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rival->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rival->setAttribute(PDO::ATTR_TIMEOUT, 0);

        try {
            // Both open the same kind of deferred transaction Laravel opens.
            $holder->exec('begin');
            $rival->exec('begin');

            // Neither has written, so neither holds anything yet: the rival can
            // still read the table both of them are about to decide on.
            $this->assertSame('0', (string) $this->firstColumn($rival, 'select count(*) from probe'));

            // The statement the bootstrap runs, read and written back unchanged.
            $current = (int) $this->firstColumn($holder, 'pragma user_version');
            $holder->exec('pragma user_version = '.$current);

            try {
                $rival->exec('insert into probe (id) values (1)');
                $this->fail('A second connection could write while the lock statement was held.');
            } catch (PDOException $exception) {
                $this->assertStringContainsString('locked', mb_strtolower($exception->getMessage()));
            }

            $rival->exec('rollback');
            $holder->exec('rollback');

            // And it changed nothing: the header value is what it was.
            $this->assertSame($current, (int) $this->firstColumn($holder, 'pragma user_version'));
        } finally {
            unset($holder, $rival);
            File::delete($path);
        }
    }

    // --- Anything else -----------------------------------------------------

    /**
     * A database the bootstrap cannot serialize is refused outright.
     *
     * Running anyway would mean performing the one operation whose entire
     * safety is the lock without the lock — and it would look fine every single
     * time until the day two people ran the command together. The refusal comes
     * before the transaction opens, so it is a clear message rather than a
     * connection error.
     */
    #[Test]
    public function a_database_that_cannot_be_locked_is_refused(): void
    {
        $default = config('database.default');

        config([
            // Never connected to: the driver name is read from the
            // configuration, and the refusal happens before anything opens.
            'database.connections.unsupported_for_bootstrap' => [
                'driver' => 'mysql',
                'database' => 'nothing',
            ],
            'database.default' => 'unsupported_for_bootstrap',
        ]);

        try {
            $this->manager->bootstrapFirstAdministrator($this->attributes());
            $this->fail('The bootstrap ran on a connection it cannot serialize.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('mysql', $exception->getMessage());
            $this->assertStringContainsString('pgsql, sqlite', $exception->getMessage());
        } finally {
            config(['database.default' => $default]);
        }
    }

    // --- Helpers -----------------------------------------------------------

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Advisory locks are a PostgreSQL feature.');
        }
    }

    /**
     * A second PostgreSQL session against the same database.
     *
     * It sees only committed data, which is all this needs: locks are server
     * state, not transaction state.
     */
    private function probeConnection(): Connection
    {
        $name = 'bootstrap_lock_probe';

        config(["database.connections.{$name}" => config('database.connections.pgsql')]);

        DB::purge($name);

        return DB::connection($name);
    }

    private function tryLock(Connection $connection, int $identifier): bool
    {
        $result = $connection->selectOne(
            'select pg_try_advisory_xact_lock(cast(? as bigint)) as acquired',
            [$identifier],
        );

        return (bool) ((array) $result)['acquired'];
    }

    /**
     * The first column of the first row, from a connection driven directly
     * rather than through Laravel.
     *
     * `PDO::query()` reports failure as `false` as well as by throwing, and the
     * scratch database is created by the test, so a `false` here is the test
     * itself being broken and worth saying so.
     */
    private function firstColumn(PDO $database, string $query): mixed
    {
        $statement = $database->query($query);

        if ($statement === false) {
            $this->fail('The scratch database refused a query the test relies on: '.$query);
        }

        return $statement->fetchColumn();
    }

    private function scratchDatabase(): string
    {
        $directory = storage_path('framework/testing/bootstrap-lock');

        File::ensureDirectoryExists($directory);

        $path = $directory.'/probe-'.bin2hex(random_bytes(8)).'.sqlite';

        $database = new PDO('sqlite:'.$path);
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('create table probe (id integer primary key)');
        unset($database);

        return $path;
    }

    private function attributes(): UserAccountAttributes
    {
        return UserAccountAttributes::fromFormData([
            'name' => 'Feruza Karimova',
            'email' => 'feruza.karimova@example.tj',
            'role' => UserRole::Administrator->value,
            'is_active' => true,
            'password' => self::PASSWORD,
        ]);
    }
}
