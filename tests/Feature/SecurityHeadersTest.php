<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_normal_web_responses_include_security_headers(): void
    {
        Route::get('/testing/security-headers/web', static fn () => response('ok'));

        $this->get('/testing/security-headers/web')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_normal_api_responses_include_security_headers(): void
    {
        Route::get('/api/testing/security-headers', static fn () => response()->json(['ok' => true]));

        $this->getJson('/api/testing/security-headers')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_web_error_responses_include_security_headers(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing/security-headers/not-found', static fn () => abort(404));

        $this->get('/testing/security-headers/not-found')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_api_error_responses_include_security_headers(): void
    {
        Route::get('/api/testing/security-headers/not-found', static fn () => abort(404));

        $this->getJson('/api/testing/security-headers/not-found')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
