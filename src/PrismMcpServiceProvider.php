<?php

declare(strict_types=1);

namespace Prism\Mcp;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Prism\Mcp\Gates\FeatureGate;
use Prism\Mcp\Gates\LaravelGate;

class PrismMcpServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prism-mcp.php', 'prism-mcp');

        $this->app->singleton(McpManager::class, fn ($app): McpManager => new McpManager(
            $app,
            $app->make(ConfigRepository::class),
            $app->make(HttpFactory::class),
            $app->make(CacheFactory::class),
        ));

        $this->app->bind(LaravelGate::class, fn ($app): LaravelGate => new LaravelGate(
            $app->make(GateContract::class),
            (string) $app->make(ConfigRepository::class)->get('prism-mcp.gates.laravel.ability', 'use-mcp-tool'),
        ));

        $this->app->bind(FeatureGate::class, function ($app): FeatureGate {
            $config = $app->make(ConfigRepository::class);

            return new FeatureGate(
                $app,
                (string) $config->get('prism-mcp.gates.fms.feature', 'mcp.tools'),
                (bool) $config->get('prism-mcp.gates.fms.per_server', true),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/prism-mcp.php' => config_path('prism-mcp.php'),
            ], 'prism-mcp-config');
        }
    }
}
