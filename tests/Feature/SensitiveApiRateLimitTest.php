<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SensitiveApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_request_limit_returns_problem_details_and_retry_headers(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum');

        for ($request = 0; $request < 60; $request++) {
            $this->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(429)
            ->assertJsonPath('status', 429)
            ->assertJsonPath('title', 'Too Many Requests')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');
    }

    public function test_write_request_limit_is_lower_than_read_limit(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum');

        for ($request = 0; $request < 10; $request++) {
            $this->patchJson('/api/v1/auth/profile', [])->assertUnprocessable();
        }

        $this->patchJson('/api/v1/auth/profile', [])
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }
}
