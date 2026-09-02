<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\TestEnvironment;

class TestEnvironmentTest extends TestCase
{
    #[Test]
    public function a_bare_environment_falls_back_to_sqlite_in_memory(): void
    {
        $resolved = TestEnvironment::resolve(null, null, null);

        $this->assertSame('sqlite', $resolved['DB_CONNECTION']);
        $this->assertSame(':memory:', $resolved['DB_DATABASE']);
        $this->assertSame('', $resolved['DB_URL']);
    }

    #[Test]
    public function an_inherited_environment_never_selects_the_application_database(): void
    {
        // What `docker compose exec app php artisan test` looks like: the whole
        // application .env arrives as real process variables, and on an
        // environment-only host there is no .env to recognise it by.
        foreach ([null, 'hydromet', 'hydromet_prod'] as $application) {
            $resolved = TestEnvironment::resolve(null, null, $application);

            $this->assertSame('sqlite', $resolved['DB_CONNECTION']);
            $this->assertSame(':memory:', $resolved['DB_DATABASE']);
        }
    }

    #[Test]
    public function an_empty_opt_in_is_not_an_opt_in(): void
    {
        foreach (['', '   '] as $optIn) {
            $this->assertSame('sqlite', TestEnvironment::resolve($optIn, null, 'hydromet')['DB_CONNECTION']);
        }
    }

    #[Test]
    public function the_explicit_opt_in_selects_the_scratch_database(): void
    {
        $resolved = TestEnvironment::resolve('pgsql', null, 'hydromet');

        $this->assertSame('pgsql', $resolved['DB_CONNECTION']);
        $this->assertSame(TestEnvironment::SCRATCH_DATABASE, $resolved['DB_DATABASE']);
        $this->assertSame('', $resolved['DB_URL']);
    }

    #[Test]
    public function the_scratch_database_may_be_renamed(): void
    {
        $resolved = TestEnvironment::resolve('pgsql', 'hydromet_ci', 'hydromet');

        $this->assertSame('pgsql', $resolved['DB_CONNECTION']);
        $this->assertSame('hydromet_ci', $resolved['DB_DATABASE']);
    }

    #[Test]
    public function connection_details_are_left_to_the_caller(): void
    {
        $resolved = TestEnvironment::resolve('pgsql', null, 'hydromet');

        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
            $this->assertArrayNotHasKey($name, $resolved);
        }
    }

    #[Test]
    public function the_scratch_database_may_not_be_the_application_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run the suite against the application database [hydromet]');

        TestEnvironment::resolve('pgsql', 'hydromet', 'hydromet');
    }

    #[Test]
    public function a_blank_scratch_database_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must name a database');

        TestEnvironment::resolve('pgsql', '   ', 'hydromet');
    }

    #[Test]
    public function an_unknown_opt_in_value_is_refused_instead_of_guessed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('accepts only "pgsql"');

        TestEnvironment::resolve('mysql', null, 'hydromet');
    }

    #[Test]
    public function the_isolated_services_are_never_handed_back_to_the_caller(): void
    {
        $runs = [
            TestEnvironment::resolve(null, null, null),
            TestEnvironment::resolve('pgsql', null, 'hydromet'),
        ];

        foreach ($runs as $resolved) {
            $this->assertSame('testing', $resolved['APP_ENV']);
            $this->assertSame('array', $resolved['CACHE_STORE']);
            $this->assertSame('array', $resolved['SESSION_DRIVER']);
            $this->assertSame('sync', $resolved['QUEUE_CONNECTION']);
            $this->assertSame('array', $resolved['MAIL_MAILER']);
        }
    }

    #[Test]
    public function the_application_database_is_read_from_the_environment_file(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APP_ENV=local\nDB_DATABASE=hydromet\n");

        $this->assertSame('hydromet', TestEnvironment::applicationDatabase($path));
        $this->assertNull(TestEnvironment::applicationDatabase($path.'-missing'));

        unlink($path);
    }

    #[Test]
    public function an_exported_database_outranks_a_stale_environment_file(): void
    {
        // phpdotenv is immutable, so this is the database the application really
        // uses. A .env left behind from an earlier deployment must not hide it.
        $path = (string) tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "DB_DATABASE=hydromet_old\n");

        $this->assertSame('hydromet_live', TestEnvironment::applicationDatabaseFor($path, 'hydromet_live'));

        unlink($path);
    }

    #[Test]
    public function a_blank_or_absent_exported_database_falls_back_to_the_environment_file(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "DB_DATABASE=hydromet\n");

        foreach ([null, '', '   '] as $exported) {
            $this->assertSame('hydromet', TestEnvironment::applicationDatabaseFor($path, $exported));
        }

        $this->assertNull(TestEnvironment::applicationDatabaseFor($path.'-missing', null));

        unlink($path);
    }

    #[Test]
    public function a_scratch_name_colliding_with_the_exported_database_is_refused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "DB_DATABASE=hydromet_old\n");

        $application = TestEnvironment::applicationDatabaseFor($path, 'hydromet_live');
        unlink($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('application database [hydromet_live]');

        TestEnvironment::resolve('pgsql', 'hydromet_live', $application);
    }
}
