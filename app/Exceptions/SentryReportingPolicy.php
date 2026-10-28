<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\RequestExceptionInterface;
use Throwable;

final class SentryReportingPolicy
{
    private const REPORTABLE_HTTP_STATUSES = [500, 503];

    public static function shouldSend(Throwable $exception): bool
    {
        $status = self::httpStatus($exception);

        return $status === null || in_array($status, self::REPORTABLE_HTTP_STATUSES, true);
    }

    private static function httpStatus(Throwable $exception): ?int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => $exception->hasStatus()
                ? $exception->status()
                : 403,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof ModelNotFoundException,
            $exception instanceof RecordNotFoundException,
            $exception instanceof RecordsNotFoundException,
            $exception instanceof BackedEnumCaseNotFoundException => 404,
            $exception instanceof OriginMismatchException => 403,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof RequestExceptionInterface => 400,
            default => null,
        };
    }
}
