<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

class InertiaErrorPagesTest extends TestCase
{
    public function test_forbidden_route_renders_the_inertia_error_page_with_original_status(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing/errors/forbidden', static fn () => abort(403));

        $this->get('/testing/errors/forbidden')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Errors/Show')
                ->where('status', 403),
            );
    }

    public function test_unknown_web_route_renders_the_inertia_error_page_with_original_status(): void
    {
        config(['app.debug' => false]);
        $this->get('/testing/errors/not-found')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Errors/Show')
                ->where('status', 404),
            );
    }

    public function test_production_server_error_renders_a_generic_inertia_page_without_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing/errors/server-error', static function (): never {
            throw new RuntimeException('private database connection and credential details');
        });

        $response = $this->get('/testing/errors/server-error');

        $response->assertInternalServerError()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Errors/Show')
                ->where('status', 500)
                ->missing('message')
                ->missing('exception'),
            );
        $this->assertStringNotContainsString('private database connection', $response->getContent());
        $this->assertStringNotContainsString(RuntimeException::class, $response->getContent());
    }

    public function test_inertia_navigation_receives_json_error_page_and_preserves_http_status(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing/errors/inertia-forbidden', static fn () => abort(403));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->get('/testing/errors/inertia-forbidden')
            ->assertForbidden()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Errors/Show')
            ->assertJsonPath('props.status', 403);
    }

    public function test_debug_mode_keeps_laravels_detailed_exception_response(): void
    {
        config(['app.debug' => true]);
        Route::get('/testing/errors/debug', static function (): never {
            throw new RuntimeException('local debugging detail');
        });

        $this->get('/testing/errors/debug')
            ->assertInternalServerError()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('local debugging detail');
    }

    public function test_api_problem_responses_are_not_replaced_by_inertia_error_pages(): void
    {
        $this->get('/api/testing/errors/not-found')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://pahnavarkar.com/errors/404')
            ->assertJsonMissingPath('component');
    }
}
