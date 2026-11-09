<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckCorsTest extends TestCase
{
    public function test_health_check_allows_cross_origin_app_clients(): void
    {
        $this->withHeader('Origin', 'https://app.example.test')
            ->get('/up')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
