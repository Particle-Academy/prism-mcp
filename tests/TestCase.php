<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Mcp\PrismMcpServiceProvider;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            PrismMcpServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // The array cache, not the null cache. `null` would make every caching
        // assertion in this suite vacuously pass — a cache that never stores
        // anything cannot be caught failing to reuse it.
        $app['config']->set('cache.default', 'array');
    }
}
