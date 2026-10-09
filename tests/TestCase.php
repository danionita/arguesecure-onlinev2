<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase rolls the database back between tests; nothing rolled
     * Redis back, so state leaked across whole runs. CalculationCache and the
     * W16 locks use the Redis facade directly, not the cache abstraction, so
     * phpunit.xml's CACHE_STORE=array does not isolate them. Their keys are
     * built from `assessments.id`, which restarts from 1 on every fresh
     * migration, so a key one run wrote was a live hit for an unrelated
     * assessment in the next — and a test asserting a cache *miss* passed or
     * failed on what earlier runs happened to leave behind.
     *
     * phpunit.xml pins REDIS_DB to a database the suite owns, so this flush
     * cannot touch a dev app sharing the same Redis server.
     *
     * Feature tests also render Inertia pages through app.blade.php, whose
     * Vite directive needs public/build/manifest.json (written without the
     * leading @, which Pint reads as a docblock annotation and reformats).
     * That file is a build
     * artifact, so the PHP test run would otherwise depend on someone having
     * run `npm run build` first — passing locally on a stale build and failing
     * in CI, which is exactly what happened. Assets are the JS job's and
     * Playwright's concern (W25); PHP tests assert responses, not bundles.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Redis::connection()->flushdb();
    }
}
