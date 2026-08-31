<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    #[Test]
    public function liveness_probe_responds_while_the_framework_is_booted(): void
    {
        $this->get('/up')->assertOk();
    }

    #[Test]
    public function readiness_reports_application_database_and_cache(): void
    {
        $response = $this->getJson('/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.application.status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonStructure(['status', 'generated_at', 'checks' => [
                'application' => ['status'],
                'database' => ['status', 'driver'],
                'cache' => ['status', 'driver'],
            ]]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function readiness_fails_when_the_database_is_unreachable(): void
    {
        config(['database.default' => 'connection-that-does-not-exist']);

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('checks.database.status', 'failed');
    }

    #[Test]
    public function readiness_never_exposes_credentials_or_hostnames(): void
    {
        config([
            'database.connections.testing_secretish' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ]);

        $body = $this->getJson('/health')->getContent();

        $this->assertIsString($body);

        foreach ([
            (string) config('app.key'),
            (string) config('database.connections.pgsql.host'),
            (string) config('database.connections.pgsql.username'),
            (string) config('database.connections.pgsql.password'),
            (string) config('redis.default.host'),
        ] as $secret) {
            // Very short values would collide with ordinary response text.
            if (strlen($secret) < 4) {
                continue;
            }

            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
