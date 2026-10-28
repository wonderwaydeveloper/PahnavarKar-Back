<?php

use App\Console\Commands\NotifyExpiringSubscriptions;
use App\Console\Commands\ProcessInactiveAccounts;
use App\Console\Commands\PruneExpiredDeviceSessions;
use App\Console\Commands\ReconcileZarinpalPayments;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\ExceptionResponse;
use Sentry\Laravel\Integration as SentryIntegration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(PruneExpiredDeviceSessions::class)->hourly()->withoutOverlapping();
        $schedule->command(ProcessInactiveAccounts::class)->dailyAt('02:00')->withoutOverlapping();
        $schedule->command(ReconcileZarinpalPayments::class)->everyTenMinutes()->withoutOverlapping();
        $schedule->command(NotifyExpiringSubscriptions::class)->dailyAt('09:00')->withoutOverlapping();
        $schedule->command('activitylog:clean', ['--force' => true])->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('queue:prune-failed', ['--hours' => 720])->dailyAt('03:10')->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
        );
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('admin.login'));
        $middleware->alias([
            'active-user' => EnsureActiveUser::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->dontReportWhen(static fn (Throwable $exception): bool => $exception instanceof HttpExceptionInterface
            && $exception->getStatusCode() >= 400
            && $exception->getStatusCode() < 500);
        SentryIntegration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $response->isClientError() && ! $response->isServerError()) {
                return SecurityHeaders::apply($response);
            }

            $status = $response->getStatusCode();
            if (
                ! $request->is('api/*')
                && ! $request->expectsJson()
                && ! config('app.debug')
                && in_array($status, [403, 404, 500], true)
            ) {
                $response = (new ExceptionResponse(
                    $exception,
                    $request,
                    $response,
                    app(Router::class),
                    app(KernelContract::class),
                ))
                    ->render('Errors/Show', ['status' => $status])
                    ->usingMiddleware(HandleInertiaRequests::class)
                    ->toResponse($request);
            }

            if (! $request->is('api/*')) {
                return SecurityHeaders::apply($response);
            }

            $original = json_decode((string) $response->getContent(), true);
            $original = is_array($original) ? $original : [];
            $originalMessage = is_string($original['message'] ?? null)
                ? $original['message']
                : ($exception->getMessage() ?: 'Request Error');
            $message = $status >= 500 && ! config('app.debug')
                ? 'Internal Server Error'
                : $originalMessage;
            $errors = $status >= 500 && ! config('app.debug')
                ? null
                : (is_array($original['errors'] ?? null) ? $original['errors'] : null);
            $title = match ($status) {
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                409 => 'Conflict',
                422 => 'Unprocessable Content',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                default => 'Request Error',
            };
            $headers = ['Content-Type' => 'application/problem+json'];
            foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $header) {
                if ($response->headers->has($header)) {
                    $headers[$header] = $response->headers->get($header);
                }
            }

            $response = response()->json([
                'type' => 'https://pahnavarkar.com/errors/'.$status,
                'title' => $title,
                'status' => $status,
                'detail' => $message,
                'instance' => $request->getPathInfo(),
                'message' => $message,
                ...($errors === null ? [] : ['errors' => $errors]),
            ], $status, $headers);

            return SecurityHeaders::apply($response);
        });
    })->create();
