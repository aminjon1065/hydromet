<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\TestEnvironment;

/**
 * Guards the isolation the rest of the suite assumes. Every assertion here holds
 * on the SQLite run and on the PostgreSQL run.
 */
class TestIsolationTest extends TestCase
{
    #[Test]
    public function every_side_effecting_service_resolves_to_an_isolated_driver(): void
    {
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));
        // phpdotenv casts the literal "null", so nothing is broadcast at all.
        $this->assertNull(config('broadcasting.default'));
    }

    #[Test]
    public function the_connection_is_sqlite_unless_the_run_explicitly_opted_into_postgresql(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        $optedIn = getenv(TestEnvironment::OPT_IN) === 'pgsql';

        $this->assertSame($optedIn ? 'pgsql' : 'sqlite', $connection);
        $this->assertSame($optedIn ? $database : ':memory:', $database);
    }

    #[Test]
    public function the_suite_never_connects_to_the_application_database(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        // Read from the bootstrap, not from the environment: by now DB_DATABASE
        // holds the scratch value the bootstrap itself wrote.
        $application = TestEnvironment::resolvedApplicationDatabase();

        $this->assertNotSame('', $database);

        if ($application !== null && $application !== '') {
            $this->assertNotSame($application, $database);
        }
    }

    #[Test]
    public function a_stray_database_url_cannot_redirect_the_run(): void
    {
        // Left in the environment a DSN silently overrides the driver, so the
        // bootstrap pins it empty on both branches. An empty url is the value
        // Laravel's ConfigurationUrlParser ignores.
        $this->assertSame('', (string) config('database.connections.'.config('database.default').'.url'));
    }
}
