<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Tests\Support\RecordingSentryTransport;
use Tests\TestCase;

class SelectiveSentryReportingTest extends TestCase
{
    protected function tearDown(): void
    {
        SentrySdk::init();

        parent::tearDown();
    }

    public function test_internal_server_errors_and_service_unavailable_are_sent_to_the_configured_transport(): void
    {
        $transport = $this->useRecordingTransport();

        Route::get('/testing/sentry/internal-error', static function (): never {
            throw new RuntimeException('internal failure');
        });
        Route::get('/testing/sentry/unavailable', static fn () => abort(503));

        $this->get('/testing/sentry/internal-error')->assertInternalServerError();
        $this->get('/testing/sentry/unavailable')->assertServiceUnavailable();

        $this->assertCount(2, $transport->events);
    }

    public function test_client_errors_and_unapproved_server_statuses_are_not_sent_to_sentry(): void
    {
        $transport = $this->useRecordingTransport();

        Route::get('/testing/sentry/not-found', static fn () => abort(404));
        Route::get('/testing/sentry/too-many-requests', static fn () => abort(429));
        Route::get('/testing/sentry/not-implemented', static fn () => abort(501));
        Route::get('/testing/sentry/validation', static fn () => throw ValidationException::withMessages([
            'name' => ['The name field is invalid.'],
        ]));

        $this->get('/testing/sentry/not-found')->assertNotFound();
        $this->get('/testing/sentry/too-many-requests')->assertTooManyRequests();
        $this->get('/testing/sentry/not-implemented')->assertStatus(501);
        $this->getJson('/testing/sentry/validation')->assertUnprocessable();

        $this->assertCount(0, $transport->events);
    }

    public function test_sentry_is_disabled_in_phpunit_without_an_explicit_test_transport(): void
    {
        $this->assertNull(config('sentry.dsn'));
    }

    public function test_sentry_does_not_send_default_pii(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    private function useRecordingTransport(): RecordingSentryTransport
    {
        $transport = new RecordingSentryTransport;
        $client = ClientBuilder::create([
            'dsn' => 'https://public@example.invalid/1',
            'before_send' => config('sentry.before_send'),
            'send_default_pii' => false,
        ])
            ->setTransport($transport)
            ->getClient();

        SentrySdk::setCurrentHub(new Hub($client));

        return $transport;
    }
}
