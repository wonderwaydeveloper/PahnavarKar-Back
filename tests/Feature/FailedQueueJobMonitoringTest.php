<?php

namespace Tests\Feature;

use App\Listeners\ReportFailedQueueJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Tests\Support\RecordingSentryTransport;
use Tests\TestCase;

class FailedQueueJobMonitoringTest extends TestCase
{
    protected function tearDown(): void
    {
        app('queue.worker')->currentJob = null;
        SentrySdk::init();

        parent::tearDown();
    }

    public function test_retry_attempts_are_not_sent_to_sentry_but_terminal_failures_are_reported_redacted(): void
    {
        $transport = $this->useRecordingTransport();
        $worker = app('queue.worker');
        $this->assertInstanceOf(Worker::class, $worker);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getName')->andReturn('App\\Jobs\\PrivatePayloadJob');
        $job->shouldReceive('getQueue')->andReturn('notifications');
        $job->shouldReceive('attempts')->andReturn(3);

        $worker->currentJob = $job;
        $exception = new RuntimeException('credential and recipient data must not be sent');

        report($exception);

        $this->assertCount(0, $transport->events);

        event(new JobFailed('database', $job, $exception));

        $this->assertCount(1, $transport->events);
        $event = $transport->events[0];
        $this->assertSame('terminal', $event->getTags()['queue_failure']);
        $this->assertSame('notifications', $event->getTags()['queue_name']);
        $this->assertSame('App\\Jobs\\PrivatePayloadJob', $event->getTags()['queue_job']);
        $this->assertSame('3', $event->getTags()['queue_attempts']);
        $this->assertSame('Queued job failed after exhausting its retries.', $event->getMessage());
        $this->assertSame([], $event->getRequest());
        $this->assertNull($event->getUser());
        $this->assertStringNotContainsString('credential and recipient data', $event->getMessage());
        $this->assertSame(
            'Queued job failed after exhausting its retries.',
            $event->getExceptions()[0]->getValue(),
        );
    }

    public function test_terminal_failure_logs_only_safe_job_metadata(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getName')->andReturn('App\\Jobs\\SafeJobName');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(2);

        Log::shouldReceive('critical')
            ->once()
            ->with('Queued job failed after exhausting its retries.', [
                'connection' => 'database',
                'queue' => 'default',
                'job' => 'App\\Jobs\\SafeJobName',
                'attempts' => 2,
                'exception_class' => RuntimeException::class,
            ]);

        app(ReportFailedQueueJob::class)->handle(
            new JobFailed('database', $job, new RuntimeException('sensitive exception details')),
        );
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

        $hub = new Hub($client);
        SentrySdk::setCurrentHub($hub);
        $this->app->instance(HubInterface::class, $hub);

        return $transport;
    }
}
