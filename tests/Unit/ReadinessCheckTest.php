<?php

namespace Tests\Unit;

use App\Support\Health\ReadinessCheck;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cache branch is covered here rather than over HTTP: the rate limiter
 * behind the `throttle` middleware resolves the same store, so a broken cache
 * never reaches the controller.
 */
class ReadinessCheckTest extends TestCase
{
    #[Test]
    public function a_healthy_environment_reports_every_check_as_ok(): void
    {
        $report = (new ReadinessCheck)->run();

        $this->assertSame(ReadinessCheck::STATUS_OK, $report['status']);
        $this->assertSame(ReadinessCheck::STATUS_OK, $report['checks']['application']['status']);
        $this->assertSame(ReadinessCheck::STATUS_OK, $report['checks']['database']['status']);
        $this->assertSame(ReadinessCheck::STATUS_OK, $report['checks']['cache']['status']);
    }

    #[Test]
    public function an_unreachable_cache_store_fails_readiness(): void
    {
        config(['cache.default' => 'store-that-does-not-exist']);

        $report = (new ReadinessCheck)->run();

        $this->assertSame(ReadinessCheck::STATUS_FAILED, $report['status']);
        $this->assertSame(ReadinessCheck::STATUS_FAILED, $report['checks']['cache']['status']);
        $this->assertSame(ReadinessCheck::STATUS_OK, $report['checks']['database']['status']);
    }

    #[Test]
    public function an_unreachable_database_fails_readiness(): void
    {
        config(['database.default' => 'connection-that-does-not-exist']);

        $report = (new ReadinessCheck)->run();

        $this->assertSame(ReadinessCheck::STATUS_FAILED, $report['status']);
        $this->assertSame(ReadinessCheck::STATUS_FAILED, $report['checks']['database']['status']);
    }

    #[Test]
    public function the_report_carries_no_connection_details_beyond_the_driver_name(): void
    {
        $report = (new ReadinessCheck)->run();

        foreach ($report['checks'] as $check) {
            $this->assertSame([], array_diff(array_keys($check), ['status', 'driver']));
        }
    }
}
