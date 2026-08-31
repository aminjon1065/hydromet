<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Backend tests assert server behaviour, not the compiled bundle. The
        // production build is verified separately by `npm run build`.
        $this->withoutVite();
    }
}
