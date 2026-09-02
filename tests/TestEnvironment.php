<?php

namespace Tests;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Makes a test run independent of the surrounding process environment.
 *
 * Compose gives every service `env_file: .env`, so the application's own
 * settings arrive in the container as real process variables. Laravel resolves
 * `env()` through `$_SERVER` before `$_ENV`, while PHPUnit's `<env>` entries
 * only reach `$_ENV` and `putenv()`, so an inherited value silently won:
 * `docker compose exec app php artisan test` ran on the Redis cache, the real
 * session driver and the development database rather than on the isolated
 * values the suite is written against.
 *
 * PHPUnit loads this bootstrap after it has applied the XML `<php>` section, so
 * the values written here are the ones a test finally sees.
 */
final class TestEnvironment
{
    /**
     * The only way to move the suite off SQLite.
     *
     * A test-scoped name, not a `DB_*` one, and deliberately so: on a host that
     * is configured through the environment alone there is no `.env` to compare
     * against, and treating a stray `DB_DATABASE` as consent would point the
     * suite at the real database. Nothing but a test runner ever sets this.
     */
    public const OPT_IN = 'HYDROMET_TEST_DB';

    /**
     * Optional override for the scratch database name.
     */
    public const SCRATCH_OVERRIDE = 'HYDROMET_TEST_DATABASE';

    /**
     * The scratch database `composer test:pgsql` and CI both use.
     */
    public const SCRATCH_DATABASE = 'hydromet_testing';

    /**
     * Settings every test run uses, whatever the caller or `.env` supply.
     *
     * @var array<string, string>
     */
    private const BASELINE = [
        'APP_ENV' => 'testing',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '4',
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'PULSE_ENABLED' => 'false',
        'TELESCOPE_ENABLED' => 'false',
        'NIGHTWATCH_ENABLED' => 'false',
    ];

    /**
     * Captured before the baseline overwrites `DB_DATABASE`, which is the only
     * moment the application's own database is still observable.
     */
    private static ?string $applicationDatabase = null;

    public static function apply(string $basePath): void
    {
        self::$applicationDatabase = self::applicationDatabaseFor(
            $basePath.'/.env',
            self::processValue('DB_DATABASE'),
        );

        $resolved = self::resolve(
            self::processValue(self::OPT_IN),
            self::processValue(self::SCRATCH_OVERRIDE),
            self::$applicationDatabase,
        );

        foreach ($resolved as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * The database this run declined to touch, as resolved at bootstrap.
     */
    public static function resolvedApplicationDatabase(): ?string
    {
        return self::$applicationDatabase;
    }

    /**
     * Resolve the environment a test run must see.
     *
     * The default is always SQLite in memory. PostgreSQL is selected only by the
     * explicit opt-in, and even then the suite is pinned to a scratch database
     * that may not be the application's own. `DB_CONNECTION`, `DB_DATABASE` and
     * `DB_URL` are always written, so neither an inherited driver nor a stray
     * DSN can redirect the run; `DB_HOST`, `DB_PORT`, `DB_USERNAME` and
     * `DB_PASSWORD` are left alone, which is how CI and a developer machine
     * reach their own PostgreSQL.
     *
     * @param  string|null  $optIn  value of HYDROMET_TEST_DB
     * @param  string|null  $scratch  value of HYDROMET_TEST_DATABASE
     * @param  string|null  $application  the database the application itself uses
     * @return array<string, string>
     *
     * @throws RuntimeException when the requested scratch database is unusable
     */
    public static function resolve(?string $optIn, ?string $scratch, ?string $application): array
    {
        if ($optIn === null || trim($optIn) === '') {
            return self::sqlite();
        }

        if (trim($optIn) !== 'pgsql') {
            throw new RuntimeException(
                self::OPT_IN.' accepts only "pgsql"; received "'.$optIn.'".'
            );
        }

        $database = trim($scratch ?? self::SCRATCH_DATABASE);

        if ($database === '') {
            throw new RuntimeException(self::SCRATCH_OVERRIDE.' must name a database.');
        }

        if ($application !== null && $database === trim($application)) {
            throw new RuntimeException(
                'Refusing to run the suite against the application database ['.$database.']. '
                .'Create a scratch database and name it in '.self::SCRATCH_OVERRIDE.'.'
            );
        }

        return [
            ...self::BASELINE,
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
        ];
    }

    /**
     * The database the application itself would use.
     *
     * The process environment comes first, because that is the order the
     * application resolves in: phpdotenv is immutable, so an exported
     * `DB_DATABASE` is never replaced by the one in `.env`. Reading `.env` first
     * would let a stale file hide the database actually in use and wave through
     * a scratch name that collides with it.
     */
    public static function applicationDatabaseFor(string $envPath, ?string $processDatabase): ?string
    {
        if ($processDatabase !== null && trim($processDatabase) !== '') {
            return $processDatabase;
        }

        return self::applicationDatabase($envPath);
    }

    /**
     * The database `.env` names, if the file exists and declares one. Absent in
     * CI and on hosts configured through the environment alone.
     */
    public static function applicationDatabase(string $envPath): ?string
    {
        if (! is_file($envPath) || ! is_readable($envPath)) {
            return null;
        }

        $contents = file_get_contents($envPath);

        if ($contents === false) {
            return null;
        }

        $database = Dotenv::parse($contents)['DB_DATABASE'] ?? null;

        return is_string($database) ? $database : null;
    }

    /**
     * @return array<string, string>
     */
    private static function sqlite(): array
    {
        return [
            ...self::BASELINE,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ];
    }

    private static function processValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) ? $value : null;
    }
}
