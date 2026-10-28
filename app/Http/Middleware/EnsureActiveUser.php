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

        if ($request->is('api/*') || $request->expectsJson()) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'دسترسی این حساب غیرفعال شده است.',
                'code' => 'account_disabled',
            ], Response::HTTP_FORBIDDEN);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->withErrors(['phone' => 'دسترسی این حساب غیرفعال شده است.']);
    }
}
