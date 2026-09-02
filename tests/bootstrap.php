<?php

use Tests\TestEnvironment;

/**
 * PHPUnit entry point.
 *
 * The test environment is applied in PHP rather than through `<env>` entries in
 * phpunit.xml, because those cannot override the process variables Compose
 * injects from `.env`. See Tests\TestEnvironment.
 */

require __DIR__.'/../vendor/autoload.php';

try {
    TestEnvironment::apply(dirname(__DIR__));
} catch (RuntimeException $exception) {
    // A misconfigured run must stop here, and say why, rather than reach a
    // database it was never meant to touch.
    fwrite(STDERR, PHP_EOL.'Test environment refused: '.$exception->getMessage().PHP_EOL.PHP_EOL);

    exit(1);
}
