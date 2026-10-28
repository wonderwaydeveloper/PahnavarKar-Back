<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Log;
use Sentry\Event;
use Sentry\Laravel\Facade as Sentry;
use Sentry\State\Scope;

final class ReportFailedQueueJob
{
    public static function isProcessingJob(): bool
    {
        if (! app()->bound('queue.worker')) {
            return false;
        }

        $worker = app('queue.worker');

        return $worker instanceof Worker && $worker->currentJob !== null;
    }

    public static function redactQueueEvent(Event $event): Event
    {
        $event->setMessage('Queued job failed after exhausting its retries.');
        $event->setRequest([]);
        $event->setUser(null);
        $event->setExtra([]);
        $event->setBreadcrumb([]);

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue('Queued job failed after exhausting its retries.');
        }

        return $event;
    }

    public function handle(JobFailed $event): void
    {
        $jobName = $event->job->getName();
        $queue = $event->job->getQueue() ?? 'default';
        $attempts = $event->job->attempts();

        $context = [
            'connection' => $event->connectionName,
            'queue' => $queue,
            'job' => $jobName,
            'attempts' => $attempts,
            'exception_class' => $event->exception::class,
        ];

        Log::critical('Queued job failed after exhausting its retries.', $context);

        Sentry::withScope(static function (Scope $scope) use ($context, $event): void {
            $scope->setTag('queue_failure', 'terminal');
            $scope->setTag('queue_connection', $event->connectionName);
            $scope->setTag('queue_name', $context['queue']);
            $scope->setTag('queue_job', $context['job']);
            $scope->setTag('queue_attempts', (string) $context['attempts']);

            Sentry::captureException($event->exception);
        });
    }
}
