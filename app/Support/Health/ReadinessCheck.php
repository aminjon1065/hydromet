<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness probe for the application container, the database and the cache
 * store (Redis in Docker and on the VPS).
 *
 * The result is deliberately free of hostnames, credentials, driver options
 * and upstream error text: it is reachable without authentication and is used
 * by container health checks and monitoring.
 */
class ReadinessCheck
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    private const CACHE_KEY = 'health:readiness';

    /**
     * @return array{status: string, checks: array<string, array<string, string>>}
     */
    public function run(): array
    {
        $checks = [
            'application' => $this->checkApplication(),
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $failed = array_filter(
            $checks,
            fn (array $check): bool => $check['status'] !== self::STATUS_OK,
        );

        return [
            'status' => $failed === [] ? self::STATUS_OK : self::STATUS_FAILED,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function checkApplication(): array
    {
        $configured = is_string(config('app.key')) && config('app.key') !== '';
        $writable = is_writable(storage_path('framework'));

        return [
            'status' => $configured && $writable ? self::STATUS_OK : self::STATUS_FAILED,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function checkDatabase(): array
    {
        $driver = (string) config('database.default');

        try {
            DB::connection()->select('select 1');

            return ['status' => self::STATUS_OK, 'driver' => $driver];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => self::STATUS_FAILED, 'driver' => $driver];
        }
    }

    /**
     * @return array<string, string>
     */
    private function checkCache(): array
    {
        $driver = (string) config('cache.default');

        try {
            $store = Cache::store();
            $token = (string) now()->getTimestampMs();

            $store->put(self::CACHE_KEY, $token, 10);
            $roundTrip = $store->get(self::CACHE_KEY);
            $store->forget(self::CACHE_KEY);

            return [
                'status' => $roundTrip === $token ? self::STATUS_OK : self::STATUS_FAILED,
                'driver' => $driver,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => self::STATUS_FAILED, 'driver' => $driver];
        }
    }
}
