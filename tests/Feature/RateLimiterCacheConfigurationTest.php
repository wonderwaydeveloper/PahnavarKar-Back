<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimiterCacheConfigurationTest extends TestCase
{
    public function test_non_local_environment_rejects_an_array_rate_limiter_store(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'staging');
        config([
            'cache.default' => 'array',
            'cache.stores.array.driver' => 'array',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Rate limiting requires a shared atomic cache store');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_non_local_environment_rejects_a_null_rate_limiter_store(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'cache.default' => 'null',
            'cache.stores.null.driver' => 'null',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Rate limiting requires a shared atomic cache store');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_non_local_environment_rejects_failover_rate_limiter_store(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'cache.default' => 'failover',
            'cache.stores.failover.driver' => 'failover',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Rate limiting requires a shared atomic cache store');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_non_local_environment_accepts_a_shared_database_store(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'cache.default' => 'database',
            'cache.stores.database.driver' => 'database',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertIsCallable(RateLimiter::limiter('otp-send'));
    }

    public function test_explicit_limiter_store_takes_precedence_over_the_default_store(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'cache.default' => 'null',
            'cache.stores.null.driver' => 'null',
            'cache.limiter' => 'redis',
            'cache.stores.redis.driver' => 'redis',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertIsCallable(RateLimiter::limiter('otp-send'));
    }
}
