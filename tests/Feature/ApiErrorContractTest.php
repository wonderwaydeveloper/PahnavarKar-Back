<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    public function test_production_api_server_errors_use_problem_details_and_hide_exception_contents(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/testing/error-contract/server-error', static function () use (&$exceptionMessage): never {
            $exceptionMessage = 'private database host and credential marker';

            throw new RuntimeException($exceptionMessage);
        });

        $response = $this->get('/api/testing/error-contract/server-error');

        $response->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://pahnavarkar.com/errors/500')
            ->assertJsonPath('title', 'Internal Server Error')
            ->assertJsonPath('status', 500)
            ->assertJsonPath('detail', 'Internal Server Error')
            ->assertJsonPath('instance', '/api/testing/error-contract/server-error')
            ->assertJsonPath('message', 'Internal Server Error');

        $this->assertStringNotContainsString($exceptionMessage, $response->getContent());
        $this->assertStringNotContainsString(RuntimeException::class, $response->getContent());
        $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
    }

    public function test_production_api_server_errors_do_not_preserve_untrusted_error_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/testing/error-contract/server-error-details', static function (): never {
            throw new class extends RuntimeException
            {
                public function render(Request $request): JsonResponse
                {
                    return response()->json([
                        'message' => 'database password marker',
                        'errors' => ['connection' => ['private host marker']],
                    ], 500);
                }
            };
        });

        $response = $this->get('/api/testing/error-contract/server-error-details');

        $response->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Internal Server Error')
            ->assertJsonMissingPath('errors');
        $this->assertStringNotContainsString('database password marker', $response->getContent());
        $this->assertStringNotContainsString('private host marker', $response->getContent());
    }

    public function test_api_validation_errors_keep_problem_details_and_field_errors(): void
    {
        Route::post('/api/testing/error-contract/validation', static function (Request $request) {
            $request->validate(['name' => ['required', 'string']]);

            return response()->noContent();
        });

        $response = $this->postJson('/api/testing/error-contract/validation', []);

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://pahnavarkar.com/errors/422')
            ->assertJsonPath('title', 'Unprocessable Content')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('instance', '/api/testing/error-contract/validation')
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    public function test_api_not_found_response_uses_the_problem_details_contract(): void
    {
        $response = $this->get('/api/testing/error-contract/not-found');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://pahnavarkar.com/errors/404')
            ->assertJsonPath('title', 'Not Found')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('instance', '/api/testing/error-contract/not-found')
            ->assertJsonPath('message', 'The route api/testing/error-contract/not-found could not be found.');
    }

    public function test_production_web_server_errors_do_not_expose_exception_contents(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing/error-contract/server-error', static function () use (&$exceptionMessage): never {
            $exceptionMessage = 'private web exception marker';

            throw new RuntimeException($exceptionMessage);
        });

        $response = $this->get('/testing/error-contract/server-error');

        $response->assertInternalServerError();
        $this->assertStringNotContainsString($exceptionMessage, $response->getContent());
        $this->assertStringNotContainsString(RuntimeException::class, $response->getContent());
        $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
    }

    public function test_non_api_json_errors_keep_the_framework_json_contract(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing/error-contract/json-error', static function () use (&$exceptionMessage): never {
            $exceptionMessage = 'private non-api exception marker';

            throw new RuntimeException($exceptionMessage);
        });

        $response = $this->getJson('/testing/error-contract/json-error');

        $response->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/json');
        $this->assertStringNotContainsString($exceptionMessage, $response->getContent());
        $this->assertArrayNotHasKey('type', $response->json());
    }
}
