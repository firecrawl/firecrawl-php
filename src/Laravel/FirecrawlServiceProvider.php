<?php

declare(strict_types=1);

namespace Firecrawl\Laravel;

use Firecrawl\Client\FirecrawlClient;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class FirecrawlServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/firecrawl.php', 'firecrawl');

        $this->app->singleton(FirecrawlClient::class, function ($app): FirecrawlClient {
            /** @var array{api_key: string|null, api_url: string|null, timeout: float, max_retries: int, backoff_factor: float} $config */
            $config = $app['config']->get('firecrawl', []);

            return FirecrawlClient::create(
                apiKey: $config['api_key'] ?? null,
                apiUrl: $config['api_url'] ?? null,
                timeoutSeconds: (float) ($config['timeout'] ?? 300),
                maxRetries: (int) ($config['max_retries'] ?? 3),
                backoffFactor: (float) ($config['backoff_factor'] ?? 0.5),
            );
        });

        $this->app->alias(FirecrawlClient::class, 'firecrawl');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/firecrawl.php' => $this->app->configPath('firecrawl.php'),
            ], 'firecrawl-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [FirecrawlClient::class, 'firecrawl'];
    }
}
