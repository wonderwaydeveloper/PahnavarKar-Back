<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        $revokeSessions = $user->suspension_revoke_sessions ?? true;
        if (
            ! $revokeSessions
            && in_array($request->path(), [
                'api/v1/auth/logout',
                'api/v1/auth/logout-all',
                'admin/logout',
            ], true)
        ) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            if ($revokeSessions) {
                $user->tokens()->delete();
            }

            return response()->json([
                'message' => 'دسترسی این حساب غیرفعال شده است.',
                'code' => 'account_disabled',
                'restriction_reason' => $user->account_status_reason,
                'suspended_until' => $user->suspended_until?->toIso8601String(),
            ], Response::HTTP_FORBIDDEN);
        }

        if ($revokeSessions) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['phone' => 'دسترسی این حساب غیرفعال شده است.']);
        }

        return response('دسترسی این حساب به‌صورت موقت تعلیق شده است.', Response::HTTP_FORBIDDEN);
    }
}
