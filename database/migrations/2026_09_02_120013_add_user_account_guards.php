<?php

use App\Domain\Identity\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts are deactivated, never deleted — enforced by the database, and the
 * role column is constrained to the roles the portal actually has.
 *
 * An account is the actor on every audit event it produced. `audit_events`
 * already refuses to lose an actor through its restrictive foreign key, but
 * that only protects accounts that happen to have written something: a person
 * who was added, given a role and then removed before acting would leave no
 * trace that they ever existed. Deactivation keeps that trace. So deletion is
 * closed outright rather than left to depend on whether a row elsewhere
 * happens to reference it.
 *
 * Additive: no existing migration is edited, no foreign key is dropped and no
 * audit history is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardExistingRoles();
        $this->addSessionVersion();

        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'sqlite' => $this->createSqliteGuards(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPostgresGuards(),
            'sqlite' => $this->dropSqliteGuards(),
            default => null,
        };

        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('session_version');
        });
    }

    /**
     * The account's security stamp.
     *
     * Deactivating an account, changing its role or changing its password has
     * to end the sessions that account already has. Deleting rows from the
     * `sessions` table only does that on one session driver; the portal's own
     * `.env.example` selects Redis, where those rows do not exist, and no
     * driver may be searched for one account's sessions. So the account carries
     * a version instead: every session records the version it was opened
     * against, and a session whose stamp has fallen behind is refused on its
     * next request.
     *
     * It is an internal counter, not a field anyone sets: no form, API, audit
     * payload or serialized model exposes it, and its absolute value means
     * nothing — only that it moved.
     */
    private function addSessionVersion(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->unsignedBigInteger('session_version')->default(1);
        });
    }

    /**
     * Refuse to run rather than to repair.
     *
     * A row holding a role the portal does not define is a question about who
     * that person should be, and the migration is not entitled to answer it by
     * rewriting or deleting the row. Stopping with the offending values named
     * lets an operator decide.
     */
    private function guardExistingRoles(): void
    {
        $known = UserRole::values();

        $unknown = DB::table('users')
            ->select('role')
            ->distinct()
            ->whereNotIn('role', $known)
            ->orderBy('role')
            ->pluck('role')
            ->all();

        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'users.role holds %d value(s) the portal does not define: %s. '
            .'Expected one of: %s. Correct these rows before running this migration; '
            .'it will not change or remove account data on its own.',
            count($unknown),
            implode(', ', array_map(static fn (mixed $role): string => '"'.(string) $role.'"', $unknown)),
            implode(', ', $known),
        ));
    }

    private function createPostgresGuards(): void
    {
        DB::statement(sprintf(
            <<<'SQL'
                ALTER TABLE users
                    ADD CONSTRAINT users_role_check
                    CHECK (role IN (%s))
            SQL,
            $this->quotedRoles(),
        ));

        // CREATE OR REPLACE, because `db:wipe` drops tables but never
        // functions: a repeated `migrate:fresh` would otherwise meet a
        // surviving definition.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hydromet_reject_user_removal()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'user accounts are never deleted; deactivate the account instead';
            END;
            $$
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_reject_delete
            BEFORE DELETE ON users
            FOR EACH ROW EXECUTE FUNCTION hydromet_reject_user_removal()
        SQL);

        // Row triggers never fire for TRUNCATE, which is the statement that
        // empties a table fastest.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_reject_truncate
            BEFORE TRUNCATE ON users
            FOR EACH STATEMENT EXECUTE FUNCTION hydromet_reject_user_removal()
        SQL);
    }

    private function dropPostgresGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS users_reject_truncate ON users');
        DB::unprepared('DROP TRIGGER IF EXISTS users_reject_delete ON users');
        DB::unprepared('DROP FUNCTION IF EXISTS hydromet_reject_user_removal()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
    }

    /**
     * SQLite has no TRUNCATE, and its truncate optimisation for an unqualified
     * DELETE is disabled on a table carrying row triggers — so a row trigger
     * covers `DELETE FROM users` with and without a WHERE clause.
     *
     * The role vocabulary is left to the application here: SQLite cannot add a
     * table constraint after creation, and the fast suite is not where an
     * unknown role would be introduced.
     */
    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_reject_delete
            BEFORE DELETE ON users
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'user accounts are never deleted; deactivate the account instead');
            END
        SQL);
    }

    private function dropSqliteGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS users_reject_delete');
    }

    private function quotedRoles(): string
    {
        return implode(', ', array_map(
            static fn (string $role): string => "'".$role."'",
            UserRole::values(),
        ));
    }
};
