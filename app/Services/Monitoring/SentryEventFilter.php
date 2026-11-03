<?php

namespace App\Services\Monitoring;

use App\Exceptions\SentryReportingPolicy;
use App\Listeners\ReportFailedQueueJob;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

final class SentryEventFilter
{
    public static function filter(Event $event, EventHint $hint): ?Event
    {
        $exception = $hint->exception;

        if (ReportFailedQueueJob::isProcessingJob()) {
            if (($event->getTags()['queue_failure'] ?? null) !== 'terminal') {
                return null;
            }

            $event = ReportFailedQueueJob::redactQueueEvent($event);
        }

        $event->setBreadcrumb(array_map(
            static function (Breadcrumb $breadcrumb): Breadcrumb {
                $sanitized = $breadcrumb;
                $message = $breadcrumb->getMessage();
                if ($message !== null) {
                    $sanitized = $sanitized->withMessage(self::redactKavenegarUrl($message));
                }
                foreach ($breadcrumb->getMetadata() as $key => $value) {
                    $sanitized = $sanitized->withMetadata($key, self::sanitizeBreadcrumbValue($value));
                }

                return $sanitized;
            },
            $event->getBreadcrumbs(),
        ));

        return $exception !== null && SentryReportingPolicy::shouldSend($exception) ? $event : null;
    }

    private static function sanitizeBreadcrumbValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redactKavenegarUrl($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::sanitizeBreadcrumbValue($item);
            }
        }

        return $value;
    }

    private static function redactKavenegarUrl(string $value): string
    {
        return preg_replace(
            '#(https://api\.kavenegar\.com/v1/)[^/?\s]+#i',
            '$1[redacted]',
            $value,
        ) ?? $value;
    }
}
