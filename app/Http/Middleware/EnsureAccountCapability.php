<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Admin\UserAccountStatusService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountCapability
{
    public function __construct(private UserAccountStatusService $statusService) {}

    public function handle(
        Request $request,
        Closure $next,
        string $capability,
    ): Response {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->restricted_until !== null && ! $user->restricted_until->isFuture()) {
            $this->statusService->clearExpiredRestriction((int) $user->getKey());
            $user->refresh();
        }

        if (! in_array($capability, $user->restricted_capabilities ?? [], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'دسترسی به این قابلیت برای حساب شما محدود شده است.',
            'code' => 'account_capability_restricted',
            'capability' => $capability,
            'restriction_reason' => $user->restriction_reason,
            'restricted_until' => $user->restricted_until?->toIso8601String(),
        ], Response::HTTP_FORBIDDEN);
    }
}
