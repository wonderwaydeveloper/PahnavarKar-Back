<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class ApiAuthenticationContractTest extends TestCase
{
    public function test_native_api_is_stateless_while_web_routes_use_session_csrf(): void
    {
        $groups = app(Router::class)->getMiddlewareGroups();

        $this->assertNotContains(EnsureFrontendRequestsAreStateful::class, $groups['api']);
        $this->assertNotContains(StartSession::class, $groups['api']);
        $this->assertNotContains(PreventRequestForgery::class, $groups['api']);
        $this->assertContains(StartSession::class, $groups['web']);
        $this->assertContains(PreventRequestForgery::class, $groups['web']);
    }
}
